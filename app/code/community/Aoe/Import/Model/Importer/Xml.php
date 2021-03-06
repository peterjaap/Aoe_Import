<?php

/**
 * XML Importer
 *
 * @author Fabrizio Branca
 * @since  2013-06-26
 */
class Aoe_Import_Model_Importer_Xml extends Aoe_Import_Model_Importer_Abstract
{
    /**
     * @var Aoe_Import_Model_ProcessorManager
     */
    protected $processorManager;

    /**
     * @var string importKey
     */
    protected $importKey = 'default';

    /**
     * @var string|bool path with sibling count
     */
    protected $skippingUntil = false;
    
    /**
     * @var string|bool whether or not to show remaining time
     */
    protected $showRemainingTime = false;

    /**
     * @var int
     */
    protected $skipCount = 0;


    /**
     * @return Aoe_Import_Model_ProcessorManager
     */
    public function getProcessorManager()
    {
        if (is_null($this->processorManager)) {
            $this->processorManager = Mage::getSingleton('aoe_import/processorManager');
            $this->processorManager->loadFromConfig();
        }
        return $this->processorManager;
    }

    /**
     * Set file name
     *
     * @param $fileName
     */
    public function setFileName($fileName)
    {
        $this->fileName = $fileName;
    }

    /**
     * @param string $skippingUntil
     */
    public function setSkippingUntil($skippingUntil)
    {
        $this->skippingUntil = $skippingUntil;
    }

    /**
     * @param string $importKey
     */
    public function setImportKey($importKey)
    {
        $this->importKey = $importKey;
    }
    
    /**
     * @param string $showRemainingTime
     */
    public function setShowRemainingTime($showRemainingTime)
    {
        $this->showRemainingTime = $showRemainingTime;
    }

    /**
     * Import
     *
     * @throws Exception
     * @return void
     */
    protected function _import()
    {
        $this->processTimesPerProduct = array();
        
        $xmlReader = Mage::getModel('aoe_import/xmlReaderWrapper');
        /* @var $xmlReader Aoe_Import_Model_XmlReaderWrapper */

        $this->message('Loading file... ', false);
        $processorReturnValue = $xmlReader->open($this->fileName);
        if ($processorReturnValue === false) {
            throw new Exception('Error while opening file in XMLReader');
        }
        $this->message('done');

        $this->message('Initialize processor manager');
        $this->getProcessorManager()->setLogFilePathTemplate(Mage::getBaseDir('log') . '/' . date('Ymd_His', $this->startTime) . '_###IDENTIFIER###.log');

        $this->message('Find processors... ', false);
        if ($this->getProcessorManager()->hasProcessorsForImportKey($this->importKey) === false) {
            Mage::throwException(sprintf('No processors found for importKey "%s"', $this->importKey));
        }
        $nodeTypesWithProcessors = $this->getProcessorManager()->getRegisteredNodeTypes();
        $this->message('done', true);
        
        if($this->showRemainingTime) $completeXmlFile = new SimpleXMLElement(file_get_contents($this->fileName));

        $this->message('Waiting for XMLReader to start...');
        $nodeCount = 1;
        while ($xmlReader->read()) {

            if ($this->wasShutDown()) {
                $this->endTime = microtime(true);
                $this->message('========================== Aborting... ==========================');
                $this->message($this->getImporterSummary());
                exit;
            }
            if (in_array($xmlReader->nodeType, $nodeTypesWithProcessors)) {
                $processors = $this->getProcessorManager()->findProcessors($this->importKey, $xmlReader->getPath(), $xmlReader->nodeType);
                if(count($processors) === 0) {
                    continue;
                }
        
                if($this->showRemainingTime) $this->totalElements = count($completeXmlFile->xpath($xmlReader->getPath()));
                try {

                    $path = $xmlReader->getPath();
                    $pathWithSiblingCount = $xmlReader->getPathWithSiblingCount();
                    $simpleXmlNode = new SimpleXMLElement($xmlReader->readOuterXml());

                    $this->processElement(
                        $simpleXmlNode,
                        $this->importKey,
                        $xmlReader->nodeType,
                        $path,
                        $pathWithSiblingCount,
                        $nodeCount++
                    );

                } catch (Exception $e) {
                    $message = "Path: $pathWithSiblingCount\n" . $e->getMessage() . "\n";
                    echo $message;
                    file_put_contents(Mage::getBaseDir('log') . '/' . date('Ymd_His', $this->startTime) . '.log', $message);
                }

                // capture some global statistics
                $this->incrementPathCounter($xmlReader->getPath());
            }

        }

        $this->finishProcessing();

        $xmlReader->close();
    }

    protected function processElement(SimpleXMLElement $element, $importKey, $nodeType, $path, $countedPath, $correlationIdentifier)
    {
        try {
            foreach ($this->getProcessorManager()->findProcessors($importKey, $path, $nodeType) as $processor) {
                
                /* @var $processor Aoe_Import_Model_Processor_Xml_Abstract */
                if($this->showRemainingTime) $startTimestamp = microtime(true);
                $processor->setPath($countedPath);
                $processor->setData($element);
                $processor->run();
                if($this->showRemainingTime) {
                    array_push($this->processTimesPerProduct, ((microtime(true)-$startTimestamp)));
                    $timeLeft = (array_sum($this->processTimesPerProduct) / count($this->processTimesPerProduct))*($this->totalElements-$correlationIdentifier);
                
                    // output to the console
                    $this->message(
                        sprintf(
                            "==> (%s - %s) - %s",
                            $correlationIdentifier .' / ' . $this->totalElements,
                            'estimated time left; ' . gmdate("H:i:s", $timeLeft),
                            $processor->getSummary()
                        )
                    );
                } else {
                    $this->message(
                        sprintf(
                            "==> (%s)  %s",
                            $correlationIdentifier,
                            $processor->getSummary()
                        )
                    );
                }
            }
        } catch (Aoe_Import_Model_Importer_Xml_SkipElementException $e) {
            // NOOP
        } catch (Exception $e) {
            // we really should never get here because exception should be handled inside the processor
            $this->message(
                sprintf(
                    "==> (%s) EXCEPTION: %s",
                    $correlationIdentifier,
                    $e->getMessage()
                )
            );
            Mage::logException($e);
        }
    }

    protected function finishProcessing()
    {
        // NOOP
    }

    /**
     * Get summary
     *
     * @return string
     */
    public function getImporterSummary()
    {
        $summary = '';

        $summary .= "Importer statistics:\n";
        $summary .= "====================\n";
        $summary .= "File: {$this->fileName}\n";
        $summary .= "ImportKey: {$this->importKey}\n";
        $summary .= "\n";

        $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
        $basePath = rtrim(Mage::getBaseDir(), '/') . '/';

        $summary .= "Active processors:\n";
        $summary .= "------------------\n";
        foreach ($this->getProcessorManager()->getAllUsedProcessors() as $processor) {
            /* @var $processor Aoe_Import_Model_Processor_Xml_Abstract */
            $logFile = $processor->getLogFilePath();
            $summary .= '- ' . get_class($processor) . "\n";
            $summary .= "  Detailed log file: {$logFile}\n";
            if(strpos($logFile, $basePath) === 0) {
                $logUrl = $baseUrl . substr($logFile, strlen($basePath));
                $summary .= "  Detailed log file URL: {$logUrl}\n";
            }
        }
        $summary .= "\n";

        $summary .= "Processed paths:\n";
        $summary .= "----------------\n";
        if (count($this->pathCounter)) {
            foreach ($this->pathCounter as $type => $amount) {
                $summary .= "- $type: $amount\n";
            }
        }
        $summary .= "\n";

        $summary .= "Statistics:\n";
        $summary .= "----------------\n";
        $total = array_sum($this->pathCounter);
        $summary .= "Total Records: " . $total . "\n";
        $duration = $this->endTime - $this->startTime;
        $summary .= "Total Duration: " . floor($duration/3600).gmdate(':i:s',$duration) . "\n";
        $timePerImport = $duration / $total;
        $summary .= "Duration/Record: " . number_format($timePerImport, 4) . " sec\n";
        $processesPerMinute = (1 / $timePerImport) * 60;
        $summary .= "Records/Minute: " . intval($processesPerMinute) . "\n";
        $summary .= "\n";

        return $summary;
    }
}

