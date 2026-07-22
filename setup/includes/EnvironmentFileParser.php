<?php
class EnvironmentFileParser {
    private $filePath;
    private $data = [];

    public function __construct($filePath) {
        $this->filePath = $filePath;
        if (file_exists($filePath)) {
            $this->data = include $filePath;
        }
    }

    public function set($key, $value) {
        $this->data[$key] = $value;
    }

    public function save() {
        $content = "<?php\n\nreturn " . var_export($this->data, true) . ";\n";
        file_put_contents($this->filePath, $content);
    }

    public function load() {
        return $this->data;
    }
}