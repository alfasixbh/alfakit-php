<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * A user who has to verify their email address (auth kit tests).
 */
class VerifiableUser extends MfaUser implements MustVerifyEmail {}
