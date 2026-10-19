<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Domain\Model;

use NITSAN\NsT3AL\Utility\LogContentNormalizer;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Logs extends AbstractEntity
{
    protected \DateTime $crdate;

    /**
    * @var string
    */
    protected string $extensionName = '';

    /**
    * @var string
    */
    protected string $sourceFile = '';

    /**
    * @var string
    */
    protected string $outputFile = '';

    /**
    * @var string
    */
    protected string $content = '';

    /**
    * @var string
    */
    protected string $requestMode = '';

    /**
    * @var string
    */
    protected string $targetLanguage = '';

    /**
     * @return string
     */
    public function getOutputFile(): string
    {
        return $this->outputFile;
    }

    /**
     * @return array<string, list<array{type: string, text: string}>>
     */
    public function getContentArray(): array
    {
        return LogContentNormalizer::normalize($this->content);
    }

    public function getCrdate(): \DateTime
    {
        return $this->crdate;
    }

    public function getRequestMode(): string
    {
        return $this->requestMode;
    }
}
