<?php

namespace App\Services;

use TCPDF;

class InvoicePdfGenerator
{
    /**
     * Generate an invoice PDF for the order.
     *
     * @param string $pdfAbs Absolute path to save the PDF
     * @param array $customer Customer data
     * @param array $order Order data
     * @param string $orderNo Order number
     */
    public function generate(string $pdfAbs, array $customer, array $order, string $orderNo): void
    {
        @mkdir(dirname($pdfAbs), 0775, true);

        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        // Company header
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetTextColor(0, 128, 0); // Green
        $pdf->Cell(0, 10, 'SPEEDY BARCODES', 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0); // Black
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, '1712 Pioneer Ave, Suite 1665', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Cheyenne, WY 82001', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Telephone: (888) 511-0266', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Fax: (307) 222-0186', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Email: Sales@SpeedyBarcodes.com', 0, 1, 'L');
        $pdf->Ln(5);

        // Order details (top right)
        $pdf->SetXY(120, 15);
        $pdf->SetFont('helvetica', '', 10);
        if (isset($order['date_formatted'])) {
            $pdf->Cell(0, 5, 'Purchase Date: ' . $order['date_formatted'], 0, 1, 'L');
        }
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(0, 0, 255); // Blue
        if (isset($order['order_no'])) {
            $pdf->Cell(0, 6, 'Order Number: ' . $order['order_no'], 0, 1, 'L');
        }
        if (isset($order['id'])) {
            $pdf->Cell(0, 6, 'Account Number: ' . $order['id'], 0, 1, 'L');
        }
        $pdf->SetTextColor(0, 0, 0); // Black
        $pdf->Ln(5);

        // Invoice title
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->Cell(0, 10, 'Invoice', 0, 1, 'C');
        $pdf->Ln(5);

        // Introductory message
        $pdf->SetFont('helvetica', '', 10);
        $intro = "Thank you for your order. Please save this invoice as it contains a download link to access the zip file containing your complete order. This is where you will find all of your digital barcode images. Also, you can click on the individual links below and download the individual parts of the order you need.";
        $pdf->MultiCell(0, 5, $intro, 0, 'L');
        $pdf->Ln(5);

        // Sold To section
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, 'Sold To:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        if (isset($customer['name'])) {
            $pdf->Cell(0, 5, $customer['name'], 0, 1, 'L');
        }
        if (isset($customer['address']['formatted'])) {
            $pdf->Cell(0, 5, $customer['address']['formatted'], 0, 1, 'L');
        }
        if (isset($customer['address']['country'])) {
            $country = $customer['address']['country'];
            $countryName = $this->getCountryName($country);
            $pdf->Cell(0, 5, $countryName, 0, 1, 'L');
        }
        if (isset($customer['phone']['formatted'])) {
            $pdf->Cell(0, 5, 'Phone: ' . $this->formatPhone($customer['phone']), 0, 1, 'L');
        }
        if (isset($customer['email'])) {
            $pdf->Cell(0, 5, 'E-Mail: ' . $customer['email'], 0, 1, 'L');
        }
        $pdf->Ln(5);

        // Download section
        $pdf->SetXY(120, 80);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(75, 5, "Download your complete barcode package, including the digital barcode images, by clicking on the button below.", 0, 'L');
        $pdf->SetTextColor(0, 128, 0); // Green
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(0, 5, '*Please be aware that no physical products are mailed with digital purchases.', 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0); // Black
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(0, 128, 0); // Green background
        $pdf->SetTextColor(255, 255, 255); // White text
        $pdf->Cell(75, 10, 'Click here to DOWNLOAD', 1, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0); // Black
        $pdf->Ln(10);

        // Items table
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(20, 8, 'Quantity', 1, 0, 'C');
        $pdf->Cell(100, 8, 'Description', 1, 0, 'C');
        $pdf->Cell(35, 8, 'Unit Price', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Amount', 1, 1, 'C');

        $pdf->SetFont('helvetica', '', 10);
        
        // Barcode items - get price from products array
        $barcodePrice = 0;
        if (isset($order['products']) && is_array($order['products'])) {
            foreach ($order['products'] as $product) {
                if (isset($product['description']) && stripos($product['description'], 'Barcode Number') !== false) {
                    $barcodePrice = (float)($product['price'] ?? 0);
                    break;
                }
            }
        }
        
        // If no price found in products, use total divided by barcode count
        if ($barcodePrice == 0 && isset($order['total_usd']) && isset($order['barcodes'])) {
            $barcodeCount = count($order['barcodes']);
            if ($barcodeCount > 0) {
                $barcodePrice = (float)$order['total_usd'] / $barcodeCount;
            }
        }
        
        if (isset($order['barcodes']) && is_array($order['barcodes'])) {
            foreach ($order['barcodes'] as $barcode) {
                $code = is_array($barcode) ? ($barcode['code'] ?? '') : (string)$barcode;
                $quantity = is_array($barcode) ? ($barcode['quantity'] ?? 1) : 1;
                $pdf->Cell(20, 6, (string)$quantity, 1, 0, 'C');
                $pdf->Cell(100, 6, 'Barcode Number - (' . $code . ')', 1, 0, 'L');
                $pdf->Cell(35, 6, '$' . number_format($barcodePrice, 2) . ' USD', 1, 0, 'R');
                $pdf->Cell(30, 6, '$' . number_format($barcodePrice * $quantity, 2) . ' USD', 1, 1, 'R');
            }
        }

        // Certificate
        if (isset($order['certificate_names']) && is_array($order['certificate_names'])) {
            foreach ($order['certificate_names'] as $name) {
                $pdf->Cell(20, 6, '1', 1, 0, 'C');
                $pdf->Cell(100, 6, 'Certificate of Authenticity (' . $name . ')', 1, 0, 'L');
                $pdf->Cell(35, 6, 'Included', 1, 0, 'R');
                $pdf->Cell(30, 6, 'Included', 1, 1, 'R');
            }
        }

        // Product list items
        $productDescriptions = [
            'UPC-12 Barcode Number List (PDF Format)',
            'UPC-12 Barcode Number List (Excel Format)',
            'UPC-12 Digital Artwork Files (EPS Format)',
            'UPC-12 Digital Artwork Files (JPG Format)',
            'UPC-12 Digital Artwork Files (PDF Format)',
            'EAN-13 Barcode Number List (PDF Format)',
            'EAN-13 Barcode Number List (Excel Format)',
            'EAN-13 Digital Artwork Files (EPS Format)',
            'EAN-13 Digital Artwork Files (JPG Format)',
            'EAN-13 Digital Artwork Files (PDF Format)',
            'PDF Additional Info',
        ];

        foreach ($productDescriptions as $desc) {
            $pdf->Cell(20, 6, '1', 1, 0, 'C');
            $pdf->Cell(100, 6, $desc, 1, 0, 'L');
            $pdf->Cell(35, 6, 'Included', 1, 0, 'R');
            $pdf->Cell(30, 6, 'Included', 1, 1, 'R');
        }

        // Totals
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(155, 8, 'Order Total:', 0, 0, 'R');
        $total = isset($order['total_usd']) ? $order['total_usd'] : '0.00';
        $pdf->Cell(30, 8, '$' . number_format((float)$total, 2) . ' USD', 1, 1, 'R');
        $pdf->Cell(155, 8, 'Credit Card Payment:', 0, 0, 'R');
        $pdf->Cell(30, 8, '$' . number_format((float)$total, 2) . ' USD', 1, 1, 'R');

        try {
            $pdf->Output($pdfAbs, 'F');
        } catch (\Throwable $e) {
            // Clean up partial file if it exists
            if (file_exists($pdfAbs)) {
                @unlink($pdfAbs);
            }
            throw new \RuntimeException("Failed to write invoice PDF: " . $e->getMessage(), 0, $e);
        }
        
        // Verify file was created
        if (!file_exists($pdfAbs) || filesize($pdfAbs) === 0) {
            throw new \RuntimeException("Invoice PDF was not created or is empty: {$pdfAbs}");
        }
    }

    private function formatPhone(array $phone): string
    {
        if (isset($phone['formatted'])) {
            return $phone['formatted'];
        }
        if (isset($phone['number'])) {
            return $phone['number'];
        }
        return '';
    }

    private function getCountryName(string $code): string
    {
        $countries = [
            'US' => 'United States of America',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'AU' => 'Australia',
            // Add more as needed
        ];
        return $countries[$code] ?? $code;
    }
}

