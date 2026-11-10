<?php

namespace App\PortalSso;

use RuntimeException;

/**
 * The portal identity can't be matched to exactly one account in this app safely.
 */
class PortalLinkConflict extends RuntimeException {}
