<?php

namespace NBCSIT\Sso\Enums;

/**
 * How a SAML metadata document reached this application.
 *
 * It matters when reading the log: a file someone uploaded was seen by a person
 * before it landed, a document pulled from a URL was not.
 */
enum SamlMetadataSource: string
{
    case Upload = 'upload';
    case Url = 'url';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'Uploaded file',
            self::Url => 'Metadata URL',
            self::Manual => 'Entered by hand',
        };
    }
}
