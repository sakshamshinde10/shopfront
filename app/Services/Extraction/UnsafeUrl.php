<?php

namespace App\Services\Extraction;

use RuntimeException;

/** A link we refuse to open, for example one that points at a private network. */
class UnsafeUrl extends RuntimeException {}
