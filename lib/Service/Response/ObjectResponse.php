<?php
/**
 * OpenRegister ObjectResponse Class
 *
 * Base response class that provides pagination and download functionality
 * for object responses in the OpenRegister application.
 *
 * @category Response
 * @package  OCA\OpenRegister\Service\Response
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   1.0.0
 *
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Response;

use OCA\OpenRegister\Db\ObjectEntity;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Base response class for object operations.
 */
class ObjectResponse
{
    /**
     * @var array The data to be returned
     */
    protected array $data;

    /**
     * @var int|null The current page number
     */
    protected ?int $page = null;

    /**
     * @var int|null The number of items per page
     */
    protected ?int $limit = null;

    /**
     * @var int|null The total number of items
     */
    protected ?int $total = null;

    /**
     * Constructor.
     *
     * @param array|ObjectEntity $data The data to be returned
     */
    public function __construct(array|ObjectEntity $data)
    {
        $this->data = is_array($data) ? $data : [$data];
    }

    /**
     * Paginate the results.
     *
     * @param int      $page     The page number
     * @param int|null $limit    The number of items per page
     * @param int|null $total    The total number of items
     *
     * @return self
     */
    public function paginate(int $page = 1, ?int $limit = 10, ?int $total = null): self
    {
        $this->page = $page;
        $this->limit = $limit;
        $this->total = $total;

        // Calculate offset and slice data
        $offset = ($page - 1) * $limit;
        $this->data = array_slice($this->data, $offset, $limit);

        return $this;
    }

    /**
     * Download the data in the specified format.
     *
     * @param string $format The format to download (json, xml, csv, excel)
     *
     * @return string The formatted data
     *
     * @throws \Exception If the format is not supported
     */
    public function download(string $format): string
    {
        $normalizer = [new ObjectNormalizer()];
        
        return match (strtolower($format)) {
            'json' => $this->downloadJson($normalizer),
            'xml'  => $this->downloadXml($normalizer),
            'csv'  => $this->downloadCsv($normalizer),
            'excel' => $this->downloadExcel(),
            default => throw new \Exception("Unsupported format: $format"),
        };
    }

    /**
     * Get the response data.
     *
     * @return array
     */
    public function getData(): array
    {
        if ($this->page !== null) {
            return [
                'data' => $this->data,
                'pagination' => [
                    'page' => $this->page,
                    'limit' => $this->limit,
                    'total' => $this->total,
                    'pages' => $this->total ? ceil($this->total / $this->limit) : 1,
                ],
            ];
        }

        return $this->data;
    }

    /**
     * Download data as JSON.
     *
     * @param array $normalizers The normalizers to use
     *
     * @return string
     */
    protected function downloadJson(array $normalizers): string
    {
        $serializer = new Serializer($normalizers, [new JsonEncoder()]);
        return $serializer->serialize($this->getData(), 'json');
    }

    /**
     * Download data as XML.
     *
     * @param array $normalizers The normalizers to use
     *
     * @return string
     */
    protected function downloadXml(array $normalizers): string
    {
        $serializer = new Serializer($normalizers, [new XmlEncoder()]);
        return $serializer->serialize($this->getData(), 'xml');
    }

    /**
     * Download data as CSV.
     *
     * @param array $normalizers The normalizers to use
     *
     * @return string
     */
    protected function downloadCsv(array $normalizers): string
    {
        $serializer = new Serializer($normalizers, [new CsvEncoder()]);
        return $serializer->serialize($this->getData(), 'csv');
    }

    /**
     * Download data as Excel.
     *
     * @return string
     */
    protected function downloadExcel(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Add headers
        $headers = array_keys(reset($this->data));
        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
        }

        // Add data
        foreach ($this->data as $row => $item) {
            foreach ($item as $col => $value) {
                $sheet->setCellValueByColumnAndRow(
                    array_search($col, $headers) + 1,
                    $row + 2,
                    is_array($value) ? json_encode($value) : $value
                );
            }
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }
} 