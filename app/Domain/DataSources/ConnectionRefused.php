<?php

namespace App\Domain\DataSources;

use RuntimeException;

/** A connection test failed; the message is safe to show to the user. */
final class ConnectionRefused extends RuntimeException {}
