<?php

namespace App\Services\RequestAssistant;

use RuntimeException;

/** A turn the requester may not take right now; the message is shown to them. */
class RequestRefused extends RuntimeException {}
