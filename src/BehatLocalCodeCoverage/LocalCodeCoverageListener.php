<?php
declare(strict_types=1);

namespace BehatLocalCodeCoverage;

use Behat\Behat\EventDispatcher\Event\AfterFeatureTested;
use Behat\Behat\EventDispatcher\Event\ExampleTested;
use Behat\Behat\EventDispatcher\Event\FeatureTested;
use Behat\Behat\EventDispatcher\Event\ScenarioLikeTested;
use Behat\Behat\EventDispatcher\Event\ScenarioTested;
use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\SuiteTested;
use LiveCodeCoverage\CodeCoverageFactory;
use LiveCodeCoverage\Storage;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class LocalCodeCoverageListener implements EventSubscriberInterface
{
    /**
     * @var bool
     */
    private $coverageEnabled = false;

    /**
     * @var CodeCoverage
     */
    private $coverage;

    /**
     * @param string $phpunitXmlPath
     * @param string $targetDirectory
     * @param string $splitBy
     */
    public function __construct(private $phpunitXmlPath, private $targetDirectory, private $splitBy)
    {
    }

    public static function getSubscribedEvents()
    {
        return [
            SuiteTested::BEFORE => 'beforeSuite',
            ScenarioTested::BEFORE => 'beforeScenario',
            ScenarioTested::AFTER => 'afterScenario',
            ExampleTested::BEFORE => 'beforeScenario',
            ExampleTested::AFTER => 'afterScenario',
            FeatureTested::AFTER => 'afterFeature',
            SuiteTested::AFTER => 'afterSuite'
        ];
    }

    public function beforeSuite(SuiteTested $event)
    {
        $this->coverageEnabled = $event->getSuite()->hasSetting('local_coverage_enabled')
            && (bool)$event->getSuite()->getSetting('local_coverage_enabled');

        if (!$this->coverageEnabled) {
            return;
        }

        try {
            $this->coverage = CodeCoverageFactory::createFromPhpUnitConfiguration($this->phpunitXmlPath);
        } catch (\RuntimeException $ex) {
            echo PHP_EOL . $ex->getMessage() . PHP_EOL;
        }
    }

    public function beforeScenario(ScenarioLikeTested $event)
    {
        if (!$this->coverageEnabled || $this->coverage === null) {
            return;
        }

        $coverageId = $event->getFeature()->getFile() . ':' . $event->getScenario()->getLine();

        $this->coverage->start($coverageId);
    }

    public function afterScenario(ScenarioLikeTested $event)
    {
        if (!$this->coverageEnabled || $this->coverage === null) {
            return;
        }

        $this->coverage->stop();
    }

    public function afterFeature(AfterFeatureTested $event)
    {
        if (!$this->coverageEnabled || $this->coverage === null || 'feature' !== $this->splitBy) {
            return;
        }

        $parts = pathinfo((string) $event->getFeature()->getFile());
        Storage::storeCodeCoverage($this->coverage, $this->targetDirectory, sprintf('%s-%s', basename($parts['dirname']), $parts['filename']));
    }

    public function afterSuite(SuiteTested $event)
    {
        // there could also be an AfterSuiteAborted event
        if (! $event instanceof AfterSuiteTested) {
            return;
        }

        if (!$this->coverageEnabled || $this->coverage === null) {
            return;
        }

        if ('suite' === $this->splitBy) {
            Storage::storeCodeCoverage($this->coverage, $this->targetDirectory, $event->getSuite()->getName());
        }

        $this->reset();
    }

    private function reset()
    {
        $this->coverage = null;
        $this->coverageEnabled = false;
    }
}
