<?php

namespace App\PortalSso;

use RuntimeException;

/**
 * The portal refused a sign-in step (bad code, bad state, missing claims).
 */
class PortalAuthException extends RuntimeException {}
