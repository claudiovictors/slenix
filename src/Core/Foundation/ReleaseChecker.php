<?php

/*
|--------------------------------------------------------------------------
| ReleaseChecker — Slenix Framework
|--------------------------------------------------------------------------
|
| Asks the GitHub API which versions of Slenix have been published (git
| tags such as v3.2.2) and works out the latest one. No dependency on the
| rest of the framework, so the same class can be reused by a standalone
| bootstrap script for projects that do not have the updater yet.
|
| Place at: src/Core/Foundation/ReleaseChecker.php
|
*/

declare(strict_types=1);

namespace Slenix\Core\Foundation;

class ReleaseChecker
{
    private const API       = 'https://api.github.com';
    private const PER_PAGE  = 100;
    private const MAX_PAGES = 5;

    /**
     * @param string $repository GitHub repository ("owner/name").
     * @param int    $timeout    Network timeout in seconds.
     */
    public function __construct(
        private readonly string $repository = Version::REPOSITORY,
        private readonly int $timeout = 8,
    ) {}

    /**
     * Returns the highest published version, or null when there are no tags.
     *
     * @param  bool        $includePrerelease Also consider tags such as v3.3.0-beta.1.
     * @return string|null Version without the leading "v" (e.g. "3.2.3").
     *
     * @throws \RuntimeException When GitHub cannot be reached or answers with an error.
     */
    public function latest(bool $includePrerelease = false): ?string
    {
        $versions = $this->versions($includePrerelease);

        return $versions === [] ? null : end($versions);
    }

    /**
     * Lists every published version in ascending semantic order.
     *
     * The GitHub tags endpoint does not guarantee any order (and "3.2.10"
     * would sort before "3.2.9" alphabetically), so all tags are collected
     * and sorted with version_compare().
     *
     * @param  bool     $includePrerelease Include tags with a pre-release suffix.
     * @return string[] Versions without the leading "v".
     *
     * @throws \RuntimeException On network or API errors.
     */
    public function versions(bool $includePrerelease = false): array
    {
        $found = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $url  = sprintf('%s/repos/%s/tags?per_page=%d&page=%d', self::API, $this->repository, self::PER_PAGE, $page);
            $tags = json_decode($this->get($url), true);

            if (!is_array($tags)) {
                throw new \RuntimeException('Unexpected response from GitHub.');
            }

            foreach ($tags as $tag) {
                $version = self::parse((string) ($tag['name'] ?? ''), $includePrerelease);

                if ($version !== null) {
                    $found[$version] = true;
                }
            }

            if (count($tags) < self::PER_PAGE) {
                break;
            }
        }

        $versions = array_map('strval', array_keys($found));
        usort($versions, 'version_compare');

        return $versions;
    }

    /**
     * Converts a git tag into a plain version string.
     *
     * Accepts "v3.2.2" and "3.2.2". Anything that is not a semantic version
     * (e.g. "nightly") returns null, and so do pre-release tags unless asked for.
     *
     * @param  string      $tag               Raw tag name.
     * @param  bool        $includePrerelease Keep pre-release suffixes.
     * @return string|null
     */
    public static function parse(string $tag, bool $includePrerelease = false): ?string
    {
        if (!preg_match('/^v?(\d+\.\d+\.\d+)(-[0-9A-Za-z.\-]+)?$/', $tag, $m)) {
            return null;
        }

        if (isset($m[2]) && !$includePrerelease) {
            return null;
        }

        return $m[1] . ($m[2] ?? '');
    }

    /**
     * URL of the GitHub page that lists what changed between two versions.
     *
     * @param  string $from Older version (no leading "v").
     * @param  string $to   Newer version (no leading "v").
     * @return string
     */
    public function compareUrl(string $from, string $to): string
    {
        return sprintf('https://github.com/%s/compare/v%s...v%s', $this->repository, $from, $to);
    }

    /**
     * Downloads the release archive (zip) of a version.
     *
     * @param  string $version Version without the leading "v".
     * @return string Raw zip bytes.
     *
     * @throws \RuntimeException On network failure or when the tag does not exist.
     */
    public function archive(string $version): string
    {
        return $this->get(
            sprintf('https://github.com/%s/archive/refs/tags/v%s.zip', $this->repository, $version),
            'application/zip'
        );
    }

    /**
     * Performs a GET request and returns the response body.
     *
     * Uses cURL when available and falls back to streams. Protected so tests
     * can replace it with a fake.
     *
     * @param  string $url
     * @param string $accept
     * @return string
     *
     * @throws \RuntimeException On network failure or a non-200 status.
     */
    protected function get(string $url, string $accept = 'application/vnd.github+json'): string
    {
        $headers = ['User-Agent: Slenix-Updater', 'Accept: ' . $accept];
        // Optional: unauthenticated GitHub requests are limited to 60 per hour per IP.
        $token = getenv('GITHUB_TOKEN');
        if (is_string($token) && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $body    = false;
        $status  = 0;
        $error   = '';

        if (function_exists('curl_init')) {
            // No curl_close(): it has had no effect since PHP 8.0 and is deprecated in PHP 8.5.
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ]]);

            $body = @file_get_contents($url, false, $context);

            $responseHeaders = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : ($http_response_header ?? []);

            if (isset($responseHeaders[0]) && preg_match('#\s(\d{3})\s#', $responseHeaders[0], $m)) {
                $status = (int) $m[1];
            }
        }

        if ($body === false || $status === 0) {
            throw new \RuntimeException('Could not reach GitHub' . ($error !== '' ? ": {$error}" : '.'));
        }

        if ($status === 403 || $status === 429) {
            throw new \RuntimeException('GitHub API rate limit reached. Try again later, or set a GITHUB_TOKEN environment variable.');
        }

        if ($status === 404) {
            throw new \RuntimeException("Repository '{$this->repository}' was not found on GitHub.");
        }

        if ($status !== 200) {
            throw new \RuntimeException("GitHub answered with HTTP {$status}.");
        }

        return (string) $body;
    }
}