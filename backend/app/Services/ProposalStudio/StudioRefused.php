<?php

namespace App\Services\ProposalStudio;

use RuntimeException;

/** Something an admin may not do right now; the message is shown to them. */
class StudioRefused extends RuntimeException {}
