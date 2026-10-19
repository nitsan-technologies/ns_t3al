<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Configuration;

use NITSAN\NsT3AL\Configuration\PasswordField;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PasswordFieldTest extends UnitTestCase
{
    #[Test]
    public function rendersAMaskedFieldWithTheCoreNameAndId(): void
    {
        $html = $this->createField(null)->render(['fieldName' => 'accessToken', 'fieldValue' => 'a"b<c']);

        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('name="accessToken"', $html);
        self::assertStringContainsString('id="em-ns_t3al-accessToken"', $html);
        self::assertStringContainsString('value="a&quot;b&lt;c"', $html);
    }

    #[Test]
    public function emptyValueRendersAnEmptyField(): void
    {
        self::assertStringContainsString('value=""', $this->createField(null)->render(['fieldName' => 'accessToken']));
    }

    #[Test]
    public function storedValueWinsOverTheTemplateDefault(): void
    {
        $html = $this->createField('stored-token')->render(['fieldName' => 'accessToken', 'fieldValue' => '']);

        self::assertStringContainsString('value="stored-token"', $html);
    }

    private function createField(?string $stored): PasswordField
    {
        return new class ($stored) extends PasswordField {
            public function __construct(private readonly ?string $stored)
            {
            }

            protected function storedValue(string $name): ?string
            {
                return $this->stored;
            }
        };
    }

    #[Test]
    public function templateUsesThePasswordFieldForTheCrowdinToken(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../ext_conf_template.txt');

        self::assertMatchesRegularExpression('/type=user\[NITSAN\\\\NsT3AL\\\\Configuration\\\\PasswordField->render\]; label=Access Token\naccessToken =/', $template);
    }
}
