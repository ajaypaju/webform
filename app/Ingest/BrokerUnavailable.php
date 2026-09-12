<?php

namespace App\Ingest;

use RuntimeException;

// Rendered as 503 + Retry-After: the submission was NOT accepted; the client must retry with the same id (I1, I2).
final class BrokerUnavailable extends RuntimeException {}
