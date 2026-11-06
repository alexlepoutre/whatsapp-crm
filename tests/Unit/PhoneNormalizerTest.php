<?php

namespace App\Tests\Unit;

use App\Service\PhoneNormalizer;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    #[TestWith(['06 12 34 56 78', '33612345678'])]
    #[TestWith(['+33 6 12 34 56 78', '33612345678'])]
    #[TestWith(['0033612345678', '33612345678'])]
    #[TestWith(['33612345678', '33612345678'])]
    #[TestWith(['', null])]
    #[TestWith([null, null])]
    public function testNormalize(?string $input, ?string $expected): void
    {
        self::assertSame($expected, PhoneNormalizer::normalize($input));
    }
}
