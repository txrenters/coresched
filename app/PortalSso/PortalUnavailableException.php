<?php

namespace App\PortalSso;

use RuntimeException;

/**
 * The portal could not be reached; not a revocation, try again later.
 */
class PortalUnavailableException extends RuntimeException {}
