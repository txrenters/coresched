<?php

namespace App\PortalSso;

use RuntimeException;

/**
 * CoreSched itself has not given this person an account; the portal cannot create one.
 */
class PortalAccessRefused extends RuntimeException {}
