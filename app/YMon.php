<?php

/**
 * ProjectMayhem
 * @author Golovkin Vladimir <rustyj4ck@gmail.com> http://www.skillz.ru
 */

namespace YMon;

use PHPExcel_Cell;
use YMon\Currency\Cbrf;
use YMon\Model\Product;
use YMon\Util\Logger;

class YMon
{

    protected $usdColumn = 'M';

    /** @var int Update interval, hours */
    protected $updateInterval = 6;

    protected $sheetsPath;

    protected $dryRun = false;

    function __construct($root = false)
    {
        $this->sheetsPath = $root ? $root : (__DIR__ . '/../sheets');
        $this->sheetsPath .= '/';
    }

    function process()
    {
        $this->listBooks();
    }

    function listBooks()
    {
        foreach (glob($this->sheetsPath . '*.xlsx') as $file) {

            $book = null;

            try {
                $book = $this->getBook($file);
            } catch (\PHPExcel_Reader_Exception $e) {
                Logger::d('Error: %s', $e->getMessage());
            }

            if ($book) {
                if ($rows = $this->parseBook($book)) {

                    if ($rows) {
                        Logger::d('Updated rows: %d', $rows);
                        $this->saveExcel($book, $file);
                    }
                }
            }
        }
    }

    /**
     * @param \PHPExcel $book
     */
    function parseBook($book)
    {

        $products = [];

        $sheet = $book->setActiveSheetIndexByName('Products');

        // Build products list

        /** @var \PHPExcel_Worksheet_RowIterator $row */
        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            $product = null;
            /** @var \PHPExcel_Cell $cell */
            foreach ($cells as $cell) {

                $value = trim($cell->getValue());

                if ($cell->getRow() > 1) {

                    $product = $product ?: new Product();

                    switch ($cell->getColumn()) {
                        case 'A':
                            $product->name = $value;
                            break;
                        case 'B':
                            $product->code = $value;
                            break;
                    }
                }

            }

            if ($product && !empty($product->code)) {
                $products [$product->code] = $product;
            }
        }

        // Fetch prices
        // Update excel

        $sheet = $book->setActiveSheetIndexByName('Prices');

        $rowID = 2;

        $prevCell = null;

        $updated = 0;

        $now = new \DateTime();

        while (1) {

            $cell = $sheet->getCellByColumnAndRow(0, $rowID);

            if (!$cell->getValue()) {

                $uptodate = false;

                // update if >= 6hours since last update
                if ($prevCell) {
                    $last = new \DateTime("@" . \PHPExcel_Shared_Date::ExcelToPHP($prevCell->getValue()));
                    $interval = $now->diff($last);
                    $diff = $interval->h + $interval->days * 24;
                    if ($this->updateInterval !== false && $diff < $this->updateInterval) {
                        $uptodate = true;
                    }
                    Logger::d('lastupd: %s delta: %dH [%s] ', $last->format('d.m.Y H:i'), $diff, ($uptodate ? '-' : '+'));
                }

                // Update!
                if (!$uptodate) {
                    $updated++;
                    $this->updateProductsRow($sheet, $products, $rowID);
                    $this->updateUSDRate($sheet, $rowID);
                }

                break;
            }

            $prevCell = $cell;
            $rowID++;

        }

        // update done
        return $updated;
    }

    /**
     * @param \PHPExcel_Worksheet $sheet
     */
    function updateUSDRate($sheet, $rowID)
    {
        $column = PHPExcel_Cell::columnIndexFromString($this->usdColumn) - 1;
        $cell = $sheet->getCellByColumnAndRow($column, $rowID);
        $usdRate = new Cbrf();
        $cell->setValue($value = $usdRate->getUSDRate());
        Logger::d('.. USD$ %.2f %d %d', $value, $column, $rowID);
    }

    /**
     * @param \PHPExcel_Worksheet $sheet
     * @param $products
     */
    function updateProductsRow($sheet, $products, $rowID)
    {


        // $sheet->insertNewRowBefore($rowID, 1);
        // $rowID++;

        $column = 1;

        $cell = $sheet->getCellByColumnAndRow(0, $rowID);

        $cell->setValue(\PHPExcel_Shared_Date::PHPToExcel(time(), false))
            ->getStyle()
            ->getNumberFormat()
            ->setFormatCode(\PHPExcel_Style_NumberFormat::FORMAT_DATE_DDMMYYYY);

        foreach ($products as $product) {
            $cell = $sheet->getCellByColumnAndRow($column, $rowID);
            $avg = $product->getPriceAvg();
            $cell->setValue($avg);
            Logger::d('.. %-25s  %.2f', $product->name, $avg);
            $column++;
        }
    }

    /**
     * @param $filename
     * \PHPExcel_Reader_Excel2007
     */
    function getBook($filename)
    {
        // $cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
        $cacheMethod = \PHPExcel_CachedObjectStorageFactory::cache_in_memory;
        $cacheSettings = array('memoryCacheSize' => '8MB');
        \PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);

        $book = $this->readExcel($filename);

        return $book;
    }

    function readExcel($inputFileName)
    {
        Logger::d('XLS.read: %s', $inputFileName);
        $objPHPExcel = \PHPExcel_IOFactory::load($inputFileName);
        return $objPHPExcel;
    }

    function saveExcel($excel, $file)
    {

        if ($this->dryRun) return;

        Logger::d('XLS.Save: %s', $file);
        $type = preg_match('@\.xlsx$@', $file) ? 'Excel2007' : 'Excel5';
        $writer = \PHPExcel_IOFactory::createWriter($excel, $type);
        $writer->save($file);
    }

    // g/setters

    /**
     * @return string
     */
    public function getUsdColumn()
    {
        return $this->usdColumn;
    }

    /**
     * @param string $usdColumn
     */
    public function setUsdColumn($usdColumn)
    {
        $this->usdColumn = $usdColumn;
    }

    /**
     * @return int
     */
    public function getUpdateInterval()
    {
        return $this->updateInterval;
    }

    /**
     * @param int $updateInterval
     */
    public function setUpdateInterval($updateInterval)
    {
        $this->updateInterval = $updateInterval;
    }

    /**
     * @return string
     */
    public function getSheetsPath()
    {
        return $this->sheetsPath;
    }

    /**
     * @param string $sheetsPath
     */
    public function setSheetsPath($sheetsPath)
    {
        $this->sheetsPath = $sheetsPath;
    }

    /**
     * @return boolean
     */
    public function isDryRun()
    {
        return $this->dryRun;
    }

    /**
     * @param boolean $dryRun
     */
    public function setDryRun($dryRun)
    {
        $this->dryRun = $dryRun;
    }

}
