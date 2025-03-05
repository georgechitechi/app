<?php

namespace GeorgeChitechi\Upgrader;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class Upgrader
{
    private $filesystem;
    private $sourcePath;
    private $backupPath;
    private $targetPath;
    private $ci4Version = '4.4.3'; // Latest stable version
    private $modelMap = []; // Add modelMap property
    private $progressBar;
    private $totalSteps = 12; // Total number of migration steps
    private $currentStep = 0;

    // Common CI3 to CI4 method name mappings
    private $methodMappings = [
        'input->post' => 'request->getPost',
        'input->get' => 'request->getGet',
        'input->server' => 'request->getServer',
        'input->cookie' => 'request->getCookie',
        'input->ip_address' => 'request->getIPAddress',
        'input->user_agent' => 'request->getUserAgent',
        'session->userdata' => 'session->get',
        'session->set_userdata' => 'session->set',
        'session->unset_userdata' => 'session->remove',
        'db->insert_id' => 'db->insertID',
        'db->affected_rows' => 'db->affectedRows',
        'uri->segment' => 'request->uri->getSegment',
        'load->view' => 'view',
        'load->library' => 'new',
        'load->model' => 'new',
        'load->helper' => 'helper',
        'config->item' => 'config',
    ];

    // Common CI3 to CI4 class name mappings
    private $classMappings = [
        'CI_Controller' => 'BaseController',
        'CI_Model' => 'Model',
        'CI_Config' => 'Config\\Config',
        'CI_Loader' => 'Config\\Services',
        'CI_Session' => 'Config\\Services::session()',
        'CI_DB' => 'Config\\Database',
        'CI_Input' => 'Config\\Services::request()',
        'CI_Output' => 'Config\\Services::response()',
    ];

    public function __construct(string $sourcePath)
    {
        $this->filesystem = new Filesystem();
        $this->sourcePath = rtrim($sourcePath, '/');
        $this->backupPath = $this->sourcePath . '_backup_' . date('Y-m-d_H-i-s');
        $this->targetPath = $this->sourcePath . '_ci4' . uniqid();
    }

    private function updateProgress(string $message): void
    {
        $this->currentStep++;
        $percentage = (int) round(($this->currentStep / $this->totalSteps) * 100);
        $barWidth = 50;
        $progressWidth = (int) round(($percentage / 100) * $barWidth);

        echo sprintf(
            "\r[%s>%s] %d%% %s",
            str_repeat("=", $progressWidth),
            str_repeat(" ", $barWidth - $progressWidth),
            $percentage,
            $message
        );

        if ($this->currentStep === $this->totalSteps) {
            echo "\n";
        }
    }

    public function upgrade(): void
    {
        try {
            // Disable error reporting for specific warnings
            error_reporting(E_ALL & ~E_WARNING);

            $this->updateProgress("Validating source...");
            $this->validateSource();

            $this->updateProgress("Creating backup...");
            $this->createBackup();

            $this->updateProgress("Downloading and setting up CI4...");
            $this->downloadAndSetupCI4();

            $this->updateProgress("Migrating controllers...");
            $this->migrateControllers();

            $this->updateProgress("Migrating models...");
            $this->migrateModels();

            $this->updateProgress("Migrating views...");
            $this->migrateViews();

            $this->updateProgress("Migrating config files...");
            $this->migrateConfig();

            $this->updateProgress("Migrating routes...");
            $this->migrateRoutes();

            $this->updateProgress("Migrating helpers...");
            $this->migrateHelpers();

            $this->updateProgress("Migrating libraries...");
            $this->migrateLibraries();

            $this->updateProgress("Creating namespaces...");
            $this->createNamespaces();

            $this->updateProgress("Updating composer.json...");
            $this->updateComposerJson();

            $this->updateProgress("Setting up environment...");
            $this->setupEnvironment();

            // Restore error reporting
            error_reporting(E_ALL);

            echo "\nMigration completed successfully! Your new CI4 project is at: {$this->targetPath}\n";
        } catch (\Exception $e) {
            echo "\nError during migration: " . $e->getMessage() . "\n";
            // Clean up if needed
            if (file_exists($this->targetPath)) {
                $this->filesystem->remove($this->targetPath);
            }
            throw $e;
        }
    }

    private function validateSource(): void
    {
        if (!file_exists($this->sourcePath . '/application')) {
            throw new \RuntimeException('Invalid CodeIgniter 3 project structure. Missing application folder.');
        }
    }

    private function createBackup(): void
    {
        $this->filesystem->mirror($this->sourcePath, $this->backupPath);
    }

    private function downloadAndSetupCI4(): void
    {
        // Create temporary directory
        $tempDir = sys_get_temp_dir() . '/ci4_temp_' . uniqid();
        $this->filesystem->mkdir($tempDir);

        try {
            // Download CI4 via Composer
            $this->runComposerCommand(
                "create-project codeigniter4/appstarter {$this->targetPath} {$this->ci4Version} --prefer-dist --no-dev"
            );

            // Clean up temporary directory
            $this->filesystem->remove($tempDir);
        } catch (\Exception $e) {
            // Clean up on failure
            $this->filesystem->remove($tempDir);
            throw new \RuntimeException('Failed to download CodeIgniter 4: ' . $e->getMessage());
        }
    }

    private function runComposerCommand(string $command): void
    {
        $composerPath = $this->findComposer();
        $fullCommand = sprintf('%s %s 2>&1', $composerPath, $command);

        exec($fullCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Composer command failed: ' . implode("\n", $output));
        }
    }

    private function findComposer(): string
    {
        $composerPaths = [
            'composer',
            'composer.phar',
            '/usr/local/bin/composer',
        ];

        foreach ($composerPaths as $path) {
            $testCommand = sprintf('%s --version 2>&1', $path);
            exec($testCommand, $output, $returnCode);

            if ($returnCode === 0) {
                return $path;
            }
        }

        throw new \RuntimeException('Composer not found. Please install Composer first.');
    }

    private function setupEnvironment(): void
    {
        // Copy env file
        if (file_exists($this->targetPath . '/env')) {
            $this->filesystem->copy(
                $this->targetPath . '/env',
                $this->targetPath . '/.env',
                true
            );
        }

        // Update environment settings
        $envContent = file_get_contents($this->targetPath . '/.env');

        // Update database settings from CI3
        $ci3DbConfig = $this->sourcePath . '/application/config/database.php';
        if (file_exists($ci3DbConfig)) {
            // Create a temporary file with modified content
            $tempConfig = "<?php\n" . preg_replace('/defined\(.*?\)\s*OR\s*exit\([^\)]+\);/', '', file_get_contents($ci3DbConfig));
            $tempFile = tempnam(sys_get_temp_dir(), 'ci3_db_config');
            file_put_contents($tempFile, $tempConfig);

            // Include the temporary file
            $db = [];
            include $tempFile;

            // Clean up
            unlink($tempFile);

            if (isset($db['default'])) {
                $envContent = preg_replace(
                    '/database.default.hostname = .*/',
                    'database.default.hostname = ' . $db['default']['hostname'],
                    $envContent
                );
                $envContent = preg_replace(
                    '/database.default.database = .*/',
                    'database.default.database = ' . $db['default']['database'],
                    $envContent
                );
                $envContent = preg_replace(
                    '/database.default.username = .*/',
                    'database.default.username = ' . $db['default']['username'],
                    $envContent
                );
                $envContent = preg_replace(
                    '/database.default.password = .*/',
                    'database.default.password = ' . $db['default']['password'],
                    $envContent
                );
            }
        }

        // Set environment to development by default
        $envContent = preg_replace(
            '/# CI_ENVIRONMENT = production/',
            'CI_ENVIRONMENT = development',
            $envContent
        );

        // Save updated env file
        file_put_contents($this->targetPath . '/.env', $envContent);
    }

    private function createTargetStructure(): void
    {
        // No need to create structure as it comes from CI4 installation
        return;
    }

    private function migrateControllers(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->sourcePath . '/application/controllers')->name('*.php');

        foreach ($finder as $file) {
            $content = $file->getContents();

            // Remove CI3 direct script access check
            $content = preg_replace(
                '/defined\(\'BASEPATH\'\) OR exit\(\'No direct script access allowed\'\);/',
                '',
                $content
            );

            // Add namespace
            $content = $this->addNamespace('App\Controllers', $content);

            // Add use statements
            $content = $this->addUseStatement($content, 'CodeIgniter\Controller');

            // Add model use statements based on usage
            if (isset($this->modelMap)) {
                foreach ($this->modelMap as $oldName => $newName) {
                    if (strpos($content, $oldName) !== false) {
                        $content = $this->addUseStatement($content, 'App\\Models\\' . $newName);
                    }
                }
            }

            // Update class extension
            $content = str_replace('extends CI_Controller', 'extends BaseController', $content);

            // Update method visibility
            $content = preg_replace('/(?<!public\s|private\s|protected\s)function\s+/', 'public function ', $content);

            // Update model references to use static calls
            if (isset($this->modelMap)) {
                foreach ($this->modelMap as $oldName => $newName) {
                    // Remove model loading statements
                    $content = preg_replace(
                        '/\$this->load->model\([\'"]' . preg_quote($oldName, '/') . '[\'"]\);/',
                        '',
                        $content
                    );

                    // Update model references to use static calls
                    $content = preg_replace(
                        '/\$this->' . preg_quote($oldName, '/') . '->/',
                        $newName . '::',
                        $content
                    );
                }
            }

            // Update CI3 syntax to CI4
            $content = $this->updateCI3Syntax($content);

            // Save to new location
            $targetFile = $this->targetPath . '/app/Controllers/' . $file->getFilename();
            $this->filesystem->dumpFile($targetFile, $content);
        }
    }

    private function updateCI3Syntax(string $content): string
    {
        // Update $this->load->view() to return view()
        $content = preg_replace(
            '/\$this->load->view\((.*?)\);/',
            'return view($1);',
            $content
        );

        // Remove model loading as we're using static calls now
        $content = preg_replace(
            '/\$this->load->model\([\'"](.+?)[\'"]\);/',
            '',
            $content
        );

        // Update library loading
        $content = preg_replace(
            '/\$this->load->library\([\'"](.+?)[\'"]\);/',
            'use App\\Libraries\\$1;',
            $content
        );

        // Update helper loading
        $content = preg_replace(
            '/\$this->load->helper\([\'"](.+?)[\'"]\);/',
            'helper(\'$1\');',
            $content
        );

        // Update input methods
        foreach ($this->methodMappings as $old => $new) {
            $content = str_replace('$this->' . $old, '$this->' . $new, $content);
        }

        // Update database queries
        $content = $this->updateDatabaseQueries($content);

        // Update session handling
        $content = $this->updateSessionHandling($content);

        return $content;
    }

    private function updateDatabaseQueries(string $content): string
    {
        // Update result() to getResult()
        $content = preg_replace('/->result\(\)/', '->getResult()', $content);

        // Update row() to getRow()
        $content = preg_replace('/->row\(\)/', '->getRow()', $content);

        // Update row_array() to getRowArray()
        $content = preg_replace('/->row_array\(\)/', '->getRowArray()', $content);

        // Update result_array() to getResultArray()
        $content = preg_replace('/->result_array\(\)/', '->getResultArray()', $content);

        // Update num_rows() to countAllResults()
        $content = preg_replace('/->num_rows\(\)/', '->countAllResults()', $content);

        return $content;
    }

    private function updateSessionHandling(string $content): string
    {
        // Update session userdata
        $content = preg_replace(
            '/\$this->session->userdata\([\'"](.+?)[\'"]\)/',
            'session()->get(\'$1\')',
            $content
        );

        // Update setting session data
        $content = preg_replace(
            '/\$this->session->set_userdata\(([^)]+)\)/',
            'session()->set($1)',
            $content
        );

        // Update unsetting session data
        $content = preg_replace(
            '/\$this->session->unset_userdata\([\'"](.+?)[\'"]\)/',
            'session()->remove(\'$1\')',
            $content
        );

        return $content;
    }

    private function addBaseControllerProperties(string $content): string
    {
        $properties = <<<'EOT'
    /**
     * Instance of the main Request object.
     *
     * @var HTTP\IncomingRequest
     */
    protected $request;

    /**
     * Instance of the main response object.
     *
     * @var HTTP\Response
     */
    protected $response;

    /**
     * Instance of logger to use.
     *
     * @var Log\Logger
     */
    protected $logger;

EOT;

        // Add properties after class declaration
        $content = preg_replace(
            '/(class \w+ extends BaseController\s*{)/',
            "$1\n$properties",
            $content
        );

        return $content;
    }

    private function migrateModels(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->sourcePath . '/application/models')->name('*.php');

        $modelMap = []; // Store old name to new name mapping

        foreach ($finder as $file) {
            $content = $file->getContents();
            $oldFilename = $file->getFilename();
            $className = pathinfo($oldFilename, PATHINFO_FILENAME);

            // Remove CI3 direct script access check
            $content = preg_replace(
                '/defined\(\'BASEPATH\'\) OR exit\(\'No direct script access allowed\'\);/',
                '',
                $content
            );

            // Convert model name to CI4 convention (UserModel instead of User_model)
            $newClassName = $this->convertToCI4ModelName($className);
            $newFilename = $newClassName . '.php';

            // Store the mapping for later use in controllers
            $modelMap[$className] = $newClassName;

            // Add namespace
            $content = $this->addNamespace('App\Models', $content);

            // Update class name and extension
            $content = preg_replace(
                '/class\s+' . preg_quote($className, '/') . '\s+extends\s+CI_Model/',
                'class ' . $newClassName . ' extends Model',
                $content
            );

            // Add use statement for Model class
            $content = $this->addUseStatement($content, 'CodeIgniter\Model');

            // Fix duplicate public visibility
            $content = preg_replace('/public\s+public\s+function/', 'public function', $content);

            // Add public visibility to methods that don't have any visibility
            $content = preg_replace('/(?<!public\s|private\s|protected\s)function\s+/', 'public function ', $content);

            // Add model properties
            $modelProperties = <<<'EOT'

    protected $table;
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [];
    protected $useTimestamps = false;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

EOT;
            // Add properties after class declaration
            $content = preg_replace(
                '/(class\s+' . preg_quote($newClassName, '/') . '\s+extends\s+Model\s*{)/',
                "$1" . $modelProperties,
                $content
            );

            // Update database methods
            $content = $this->updateDatabaseQueries($content);

            // Save to new location
            $targetFile = $this->targetPath . '/app/Models/' . $newFilename;
            $this->filesystem->dumpFile($targetFile, $content);
        }

        // Store model mapping for use in controller updates
        $this->modelMap = $modelMap;
    }

    private function convertToCI4ModelName(string $className): string
    {
        // Remove _model suffix if exists
        $name = preg_replace('/_model$/', '', strtolower($className));

        // Convert snake_case to CamelCase
        $name = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));

        // Add Model suffix
        return $name . 'Model';
    }

    private function addUseStatement(string $content, string $class): string
    {
        $lines = explode("\n", $content);

        // Find the namespace line
        $namespaceIndex = -1;
        foreach ($lines as $i => $line) {
            if (strpos($line, 'namespace ') === 0) {
                $namespaceIndex = $i;
                break;
            }
        }

        // Add use statement after namespace
        if ($namespaceIndex !== -1) {
            array_splice($lines, $namespaceIndex + 1, 0, ['', 'use ' . $class . ';']);
        }

        return implode("\n", $lines);
    }

    private function addModelProperties(string $content): string
    {
        $properties = <<<'EOT'
    protected $table;
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [];
    protected $useTimestamps = false;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

EOT;

        // Add properties after class declaration
        $content = preg_replace(
            '/(class \w+ extends Model\s*{)/',
            "$1\n$properties",
            $content
        );

        return $content;
    }

    private function migrateViews(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->sourcePath . '/application/views')->name('*.php');

        foreach ($finder as $file) {
            $content = $file->getContents();

            // Update view syntax
            $content = str_replace('<?php echo ', '<?= ', $content);

            // Update form helper syntax
            $content = $this->updateFormHelperSyntax($content);

            // Update URL helper syntax
            $content = $this->updateURLHelperSyntax($content);

            // Save to new location
            $relativePath = $file->getRelativePath();
            $targetDir = $this->targetPath . '/app/Views/' . $relativePath;
            $this->filesystem->mkdir($targetDir);
            $targetFile = $targetDir . '/' . $file->getFilename();
            $this->filesystem->dumpFile($targetFile, $content);
        }
    }

    private function updateFormHelperSyntax(string $content): string
    {
        // Update form_open()
        $content = str_replace('<?php echo form_open', '<?= form_open', $content);

        // Update form_close()
        $content = str_replace('<?php echo form_close', '<?= form_close', $content);

        // Update form_input()
        $content = str_replace('<?php echo form_input', '<?= form_input', $content);

        // Update form validation errors
        $content = preg_replace(
            '/<?php echo form_error\([\'"](.+?)[\'"]\);?>/',
            '<?= validation_show_error(\'$1\') ?>',
            $content
        );

        return $content;
    }

    private function updateURLHelperSyntax(string $content): string
    {
        // Update site_url()
        $content = str_replace('<?php echo site_url', '<?= site_url', $content);

        // Update base_url()
        $content = str_replace('<?php echo base_url', '<?= base_url', $content);

        // Update current_url()
        $content = str_replace('<?php echo current_url', '<?= current_url', $content);

        return $content;
    }

    private function addNamespace(string $namespace, string $content): string
    {
        $lines = explode("\n", $content);
        array_splice($lines, 1, 0, ["namespace $namespace;"]);
        return implode("\n", $lines);
    }

    private function migrateConfig(): void
    {
        $configFiles = [
            'config' => 'App',
            'database' => 'Database',
            'routes' => 'Routes',
            'autoload' => 'Autoload'
        ];

        foreach ($configFiles as $ci3File => $ci4File) {
            $sourceFile = $this->sourcePath . '/application/config/' . $ci3File . '.php';
            if (file_exists($sourceFile)) {
                $content = file_get_contents($sourceFile);
                // Convert array syntax to class properties
                $content = $this->convertConfigToClass($content, $ci4File);
                $targetFile = $this->targetPath . '/app/Config/' . $ci4File . '.php';
                $this->filesystem->dumpFile($targetFile, $content);
            }
        }
    }

    private function convertConfigToClass(string $content, string $className): string
    {
        // Remove the direct script access check
        $content = preg_replace('/defined\(.*?\)\s*OR\s*exit\([^\)]+\);/', '', $content);

        $template = "<?php\n\nnamespace Config;\n\nuse CodeIgniter\Config\BaseConfig;\n\nclass $className extends BaseConfig\n{\n";

        // Extract variables from the config array
        preg_match_all('/\$config\[\'([^\']+)\'\]\s*=\s*([^;]+);/', $content, $matches);

        foreach ($matches[1] as $i => $key) {
            $value = trim($matches[2][$i]);
            $template .= "    public \$$key = $value;\n";
        }

        $template .= "}\n";
        return $template;
    }

    private function migrateRoutes(): void
    {
        $sourceFile = $this->sourcePath . '/application/config/routes.php';
        if (file_exists($sourceFile)) {
            $content = file_get_contents($sourceFile);

            // Convert CI3 route syntax to CI4
            $content = preg_replace(
                '/\$route\[\'([^\']+)\'\]\s*=\s*\'([^\']+)\';/',
                '$routes->add(\'$1\', \'$2\');',
                $content
            );

            $targetFile = $this->targetPath . '/app/Config/Routes.php';
            $this->filesystem->dumpFile($targetFile, $content);
        }
    }

    private function migrateHelpers(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->sourcePath . '/application/helpers')->name('*.php');

        foreach ($finder as $file) {
            $content = $file->getContents();

            // Remove CI3 direct script access check
            $content = preg_replace(
                '/defined\(\'BASEPATH\'\) OR exit\(\'No direct script access allowed\'\);/',
                '',
                $content
            );

            // Convert helper functions to be namespaced
            $content = $this->addNamespace('App\Helpers', $content);

            $targetFile = $this->targetPath . '/app/Helpers/' . $file->getFilename();
            $this->filesystem->dumpFile($targetFile, $content);
        }
    }

    private function migrateLibraries(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->sourcePath . '/application/libraries')->name('*.php');

        foreach ($finder as $file) {
            $content = $file->getContents();

            // Remove CI3 direct script access check
            $content = preg_replace(
                '/defined\(\'BASEPATH\'\) OR exit\(\'No direct script access allowed\'\);/',
                '',
                $content
            );

            // Convert library classes to be namespaced
            $content = $this->addNamespace('App\Libraries', $content);

            $targetFile = $this->targetPath . '/app/Libraries/' . $file->getFilename();
            $this->filesystem->dumpFile($targetFile, $content);
        }
    }

    private function createNamespaces(): void
    {
        $content = <<<'EOT'
<?php

namespace Config;

use CodeIgniter\Config\AutoloadConfig;

class Autoload extends AutoloadConfig
{
    public $psr4 = [
        'App'         => APPPATH,
        'Config'      => APPPATH . 'Config',
        'App\Models'  => APPPATH . 'Models',
    ];

    public $classmap = [];
}
EOT;

        $this->filesystem->dumpFile($this->targetPath . '/app/Config/Autoload.php', $content);
    }

    private function updateComposerJson(): void
    {
        $composerJsonPath = $this->targetPath . '/composer.json';
        $composerJson = json_decode(file_get_contents($composerJsonPath), true);

        // Add any additional dependencies from CI3 project
        $ci3ComposerJson = $this->sourcePath . '/composer.json';
        if (file_exists($ci3ComposerJson)) {
            $ci3Composer = json_decode(file_get_contents($ci3ComposerJson), true);
            if (isset($ci3Composer['require'])) {
                foreach ($ci3Composer['require'] as $package => $version) {
                    if (!isset($composerJson['require'][$package])) {
                        $composerJson['require'][$package] = $version;
                    }
                }
            }
        }

        // Update composer.json
        file_put_contents(
            $composerJsonPath,
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Run composer update
        $this->runComposerCommand('update --working-dir=' . $this->targetPath);
    }
}