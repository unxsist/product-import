<?php

namespace BigBridge\ProductImport\Model\Reader;

use BigBridge\ProductImport\Api\Data\Product;
use Exception;
use BigBridge\ProductImport\Api\ProductImportLogger;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Patrick van Bergen
 */
class ProductImportCommandLogger implements ProductImportLogger
{
    /** @var OutputInterface */
    protected $output;

    /** @var int  */
    protected $failedProductCount = 0;

    /** @var int  */
    protected $okProductCount = 0;

    /** @var bool  */
    protected $exceptionOccurred = false;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function productImported(Product $product)
    {
        if ($product->isOk()) {

            $this->okProductCount++;
        } else {
            $this->failedProductCount++;

            foreach ($product->getErrors() as $error) {
                $this->output->writeln("{$error} for product '{$product->getSku()}' that starts in line {$product->lineNumber}");
            }
        }
    }

    public function handleException(Exception $e)
    {
        $this->exceptionOccurred = true;

        $this->output->writeln("<error>" . $e->getMessage() . "</error>");
    }

    public function info(string $info)
    {
        $this->output->writeln("<info>{$info}</info>");
    }

    /**
     * @return int
     */
    public function getFailedProductCount(): int
    {
        return $this->failedProductCount;
    }

    /**
     * @return int
     */
    public function getOkProductCount(): int
    {
        return $this->okProductCount;
    }

    /**
     * @return bool
     */
    public function hasExceptionOccurred(): bool
    {
        return $this->exceptionOccurred;
    }
}