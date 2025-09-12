<?php

namespace Epiclub\Engine;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;


class QrCodeGenerator
{
    protected string $storage = __DIR__ . '/../../_storage/qrcodes';

    public function __construct()
    {
        if (!is_dir($this->storage)) mkdir($this->storage, 0755, true);
    }

    /**
     * Always generate png (at this time)
     * @return string The filename (name + extention)
     */
    public function generate(string $name, string $data): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            logoPath: __DIR__ . '/assets/bender.png',
            logoResizeToWidth: 50,
            logoPunchoutBackground: true,
            labelText: 'This is the label',
            labelFont: new OpenSans(20),
            labelAlignment: LabelAlignment::Center
        );

        $filename = $name . '.png';

        $result = $builder->build();
        $result->saveToFile($this->storage . '/' . $filename);

        return $filename;
    }
}
