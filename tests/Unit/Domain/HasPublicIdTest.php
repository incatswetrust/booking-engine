<?php

use App\Domain\Concerns\HasPublicId;

it('generates a prefixed, lowercase ulid-based public id', function () {
    $model = new class
    {
        use HasPublicId;

        public static function publicIdPrefix(): string
        {
            return 'bkg';
        }
    };

    $id = $model::generatePublicId();

    expect($id)->toMatch('/^bkg_[0-9a-z]{26}$/');
});

it('generates unique ids across calls', function () {
    $model = new class
    {
        use HasPublicId;

        public static function publicIdPrefix(): string
        {
            return 'res';
        }
    };

    expect($model::generatePublicId())->not->toBe($model::generatePublicId());
});
