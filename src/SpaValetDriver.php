<?php

/**
 * Serves built single-page apps (Vite, React, SvelteKit static output, ...)
 * with SPA history fallback.
 *
 * Valet Linux ships no SPA/"serve" driver: all of its built-in drivers target
 * PHP frameworks. Point one at a compiled frontend and every client-side route
 * 404s, because BasicValetDriver only resolves real files plus an
 * index.php/index.html front controller -- and /hustle/abc has neither.
 *
 * Drop this in ~/.valet/Drivers/, which Valet loads ahead of its built-ins, so
 * nothing upstream has to be modified.
 *
 * @see https://github.com/cpriego/valet-linux
 */
class SpaValetDriver extends ValetDriver
{
    /**
     * Determine if the driver serves the request.
     *
     * Decided purely by what is on disk, so it works for any linked build
     * without extra configuration.
     *
     * @return bool
     */
    public function serves($sitePath, $siteName, $uri)
    {
        return $this->looksLikeSpaBuild($sitePath);
    }

    /**
     * Does this site path hold a static SPA build rather than a PHP app?
     *
     * Requires an index.html at the site root, and refuses anything that looks
     * like a PHP application:
     *
     *   - index.php at the root    -> WordPress and most flat PHP installs
     *   - public/index.php         -> Laravel, Symfony, Slim, Laravel-based CMSs
     *
     * Those exclusions matter: this driver is checked before BasicValetDriver,
     * so a false positive would take a PHP site offline. Note that the two
     * layouts are what BasicValetDriver itself keys on, so the test mirrors its
     * own notion of "is this a PHP app" rather than inventing a new one.
     */
    private function looksLikeSpaBuild($sitePath)
    {
        if ($this->isActualFile($sitePath . '/index.php')) {
            return false;
        }

        if ($this->isActualFile($sitePath . '/public/index.php')) {
            return false;
        }

        return $this->isActualFile($sitePath . '/index.html');
    }

    /**
     * Determine if the incoming request is for a static file.
     *
     * Returns index.html for unknown routes: that is the SPA fallback, handing
     * the decision to the client-side router. Answering as a static file rather
     * than a PHP front controller keeps nginx's own MIME, caching and etag
     * handling, instead of piping every request through PHP-FPM.
     *
     * @return string|false
     */
    public function isStaticFile($sitePath, $siteName, $uri)
    {
        $candidate = $sitePath . $uri;

        // A real file inside the site, served untouched.
        if ($this->isActualFile($candidate) && $this->isWithinSite($candidate, $sitePath)) {
            return $candidate;
        }

        // A directory request, e.g. / or /hustles/
        $trimmed = rtrim($uri, '/');
        if ($trimmed !== '' && $this->isActualFile($sitePath . $trimmed . '/index.html')) {
            return $sitePath . $trimmed . '/index.html';
        }

        // SPA fallback, for extension-less paths only. Returning index.html for
        // a missing asset would make the browser execute HTML as JavaScript, so
        // those have to 404 instead.
        if ($this->isRoutablePath($uri) && $this->looksLikeSpaBuild($sitePath)) {
            return $sitePath . '/index.html';
        }

        return false;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     *
     * server.php skips isStaticFile() entirely when the URI is "/", so the site
     * root can only be answered here -- this is the single most common way to
     * get a blank 404 at the root while every other route works.
     *
     * Returning the HTML entry document is a sanctioned shape: BasicValetDriver
     * does exactly this through asHtmlIndexFileInDirectory(), and server.php
     * simply chdir()s and require()s the result. A built index.html contains no
     * PHP, so it is emitted verbatim as the response body.
     *
     * @return string|false
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        // Mirrors the guard in isStaticFile(): only the root and client-side
        // routes may receive the entry document. A missing asset reaching here
        // would be handed index.html and executed as JavaScript.
        if (! $this->isRoutablePath($uri)) {
            return false;
        }

        $entry = $sitePath . '/index.html';

        if (! $this->looksLikeSpaBuild($sitePath) || ! $this->isActualFile($entry)) {
            return false;
        }

        $_SERVER['PHP_SELF'] = $uri;
        $_SERVER['SCRIPT_FILENAME'] = $entry;
        $_SERVER['SCRIPT_NAME'] = '/index.html';
        $_SERVER['DOCUMENT_ROOT'] = $sitePath;

        return $entry;
    }

    /**
     * Could this URI be a client-side route rather than a file request?
     *
     * Anything with a file extension is an asset request; those must resolve to
     * a real file or 404, never to index.html.
     */
    private function isRoutablePath($uri)
    {
        return pathinfo($uri, PATHINFO_EXTENSION) === '';
    }

    /**
     * Is this a real file, rather than a directory?
     */
    protected function isActualFile($path)
    {
        return ! is_dir($path) && file_exists($path);
    }

    /**
     * Confine the resolved path to the site directory.
     *
     * $uri arrives from REQUEST_URI after rawurldecode(), so percent-encoded
     * traversal can survive nginx's normalisation. Compare realpaths, since
     * $sitePath is itself a symlink into ~/.valet/Sites and a plain prefix
     * comparison against the unresolved path would never match.
     */
    private function isWithinSite($path, $sitePath)
    {
        $real = realpath($path);
        $root = realpath($sitePath);

        return $real !== false
            && $root !== false
            && strpos($real, rtrim($root, '/') . '/') === 0;
    }
}