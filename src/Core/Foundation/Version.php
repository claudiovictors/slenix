<?php

/*
|--------------------------------------------------------------------------
| Version — Slenix Framework
|--------------------------------------------------------------------------
|
| Single source of truth for the installed framework version. The updater
| compares this value with the latest tag published on GitHub.
|
| RELEASE CHECKLIST: bump CURRENT before tagging a new release, e.g.
| when you run `git tag -a v3.2.3`, CURRENT must already be '3.2.3'.
|
| Place at: src/Core/Foundation/Version.php
|
*/

declare(strict_types=1);

namespace Slenix\Core\Foundation;

final class Version
{
    /** Installed framework version (semantic version, no leading "v"). */
    public const CURRENT = '3.2.3';

    /** GitHub repository that publishes the release tags ("owner/name"). */
    public const REPOSITORY = 'claudiovictors/slenix';
}