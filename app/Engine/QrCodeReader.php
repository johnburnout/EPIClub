<?php

namespace Epiclub\Engine;


class QrCodeReader
{
    protected string $storage = __DIR__ . '/../../_storage/qrcodes';

    /**
     * Always read png (at this time)
     * @param string $filename The name + extention of file
     */
    public function read(string $filename)
    {
        if (file_exists($this->storage . '/' . $filename)) {
            header('Content-Type: image/png');
            return readfile($filename);
        }

        http_response_code(204);
        return null;
    }
}
