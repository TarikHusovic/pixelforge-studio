<?php

declare(strict_types=1);

use App\Enums\GenerationStatus as Status;

it('only moves forward, and never away from succeeded or failed', function (Status $from, Status $to, bool $allowed) {
    expect($from->canBecome($to))->toBe($allowed);
})->with([
    [Status::Pending, Status::Queued, true],
    [Status::Pending, Status::Succeeded, true],
    [Status::Queued, Status::Processing, true],
    [Status::Processing, Status::Succeeded, true],
    [Status::Processing, Status::Failed, true],
    [Status::Queued, Status::Pending, false],
    [Status::Processing, Status::Queued, false],
    [Status::Processing, Status::Processing, false],
    [Status::Succeeded, Status::Failed, false],
    [Status::Failed, Status::Succeeded, false],
    [Status::Succeeded, Status::Succeeded, false],
]);
