<?php

namespace NBCSIT\Sso\Support;

use RuntimeException;

/**
 * The document is not something this application can configure an identity
 * provider from, and no part of it is usable.
 *
 * Distinct from a warning: a warning means the import went ahead with something
 * defaulted or ignored. This means there is nothing to go ahead with, and its
 * message is written to be read by the administrator who caused it.
 */
class MetadataUnreadable extends RuntimeException {}
