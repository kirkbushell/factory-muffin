<?php

namespace League\FactoryMuffin;

use Exception;
use League\FactoryMuffin\Exception\DeleteMethodNotFoundException;
use League\FactoryMuffin\Exception\DeletingFailedException;
use League\FactoryMuffin\Exception\DirectoryNotFoundException;
use League\FactoryMuffin\Exception\NoDefinedFactoryException;
use League\FactoryMuffin\Exception\SaveFailedException;
use League\FactoryMuffin\Exception\SaveMethodNotFoundException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Class FactoryMuffin.
 *
 * @package League\FactoryMuffin
 * @author  Zizaco <zizaco@gmail.com>
 * @author  Scott Robertson <scottymeuk@gmail.com>
 * @author  Graham Campbell <graham@mineuk.com>
 * @license <https://github.com/thephpleague/factory-muffin/blob/master/LICENSE> MIT
 */
class FactoryMuffin
{
    /**
     * The array of factories.
     *
     * @var array
     */
    private $factories = array();

    /**
     * The array of objects we have created.
     *
     * @var array
     */
    private $saved = array();

    /**
     * This is the method used when saving objects.
     *
     * @var string
     */
    private $saveMethod = 'save';

    /**
     * This is the method used when deleting objects.
     *
     * @var string
     */
    private $deleteMethod = 'delete';

    /**
     * @var Faker
     */
    private $faker;

    /**
     * Faker Localization
     * @var string
     */
    private $fakerLocale = 'en_EN';

    /**
     * Constructor for FactoryMuffin
     */
    public function __construct()
    {
        $this->faker = \Faker\Factory::create($this->fakerLocale);;
    }

    /**
     * Set the Faker Locale
     *
     * @param string $local
     */
    public function setFakerLocale($local)
    {
        $this->fakerLocale = $local;
    }

    /**
     * Set the method we use when saving objects.
     *
     * @param string $method
     *
     * @return void
     */
    public function setSaveMethod($method)
    {
        $this->saveMethod = $method;
    }

    /**
     * Set the method we use when deleting objects.
     *
     * @param string $method
     *
     * @return void
     */
    public function setDeleteMethod($method)
    {
        $this->deleteMethod = $method;
    }

    /**
     * Returns multiple versions of an object.
     *
     * These objects are generated by the create function,
     * so are saved to the database.
     *
     * @param string $model
     * @param int    $times
     * @param array  $attr
     *
     * @return object[]
     */
    public function seed($model, $times = 1, array $attr = array())
    {
        $seeds = array();
        while ($times > 0) {
            $seeds[] = $this->create($model, $attr);
            $times--;
        }

        return $seeds;
    }

    /**
     * Creates and saves in db an instance of the model.
     *
     * This object will be generated with mock attributes.
     *
     * @param string $model Model class name.
     * @param array  $attr  Model attributes.
     *
     * @throws \League\FactoryMuffin\Exception\SaveFailedException
     *
     * @return object
     */
    public function create($model, array $attr = array())
    {
        $obj = $this->make($model, $attr, true);

        if (!$this->save($obj)) {
            if (isset($obj->validationErrors) && $obj->validationErrors) {
                throw new SaveFailedException($model, $obj->validationErrors);
            }

            throw new SaveFailedException($model);
        }

        return $obj;
    }

    /**
     * Make an instance of the model.
     *
     * @param string $model Model class name.
     * @param array  $attr  Model attributes.
     * @param bool   $save  Are we saving an object, or just creating an instance?
     *
     * @return object
     */
    private function make($model, array $attr, $save)
    {
        $obj = new $model();

        if ($save) {
            $this->saved[] = $obj;
        }

        // Get the factory attributes for that model
        $attributes = $this->attributesFor($obj, $attr);

        foreach ($attributes as $attr => $value) {
            $obj->$attr = $value;
        }

        return $obj;
    }

    /**
     * Save our object to the db, and keep track of it.
     *
     * @param object $object The model instance.
     *
     * @throws \League\FactoryMuffin\Exception\SaveMethodNotFoundException
     *
     * @return mixed
     */
    private function save($object)
    {
        if (!method_exists($object, $method = $this->saveMethod)) {
            throw new SaveMethodNotFoundException($object, $method);
        }

        return $object->$method();
    }

    /**
     * Return an array of saved objects.
     *
     * @return object[]
     */
    public function saved()
    {
        return $this->saved;
    }

    /**
     * Is the object saved?
     *
     * @param object $object The model instance.
     *
     * @return bool
     */
    public function isSaved($object)
    {
        return in_array($object, $this->saved, true);
    }

    /**
     * Call the delete method on any saved objects.
     *
     * @throws \League\FactoryMuffin\Exception\DeletingFailedException
     * @throws \League\FactoryMuffin\Exception\DeleteMethodNotFoundException
     *
     * @return void
     */
    public function deleteSaved()
    {
        $exceptions = array();
        $method = $this->deleteMethod;
        foreach ($this->saved() as $saved) {
            try {
                if (!method_exists($saved, $method)) {
                    throw new DeleteMethodNotFoundException($saved, $method);
                }

                $saved->$method();
            } catch (Exception $e) {
                $exceptions[] = $e;
            }
        }

        $this->saved = array();

        if ($exceptions) {
            throw new DeletingFailedException($exceptions);
        }
    }

    /**
     * Return an instance of the model.
     *
     * This does not save it in the database. Use create for that.
     *
     * @param string $model Model class name.
     * @param array  $attr  Model attributes.
     *
     * @return object
     */
    public function instance($model, array $attr = array())
    {
        return $this->make($model, $attr, false);
    }

    /**
     * Returns the mock attributes for the model.
     *
     * @param object $object The model instance.
     * @param array  $attr   Model attributes.
     *
     * @return array
     */
    public function attributesFor($object, array $attr = array())
    {
        $factory_attrs = $this->getFactoryAttrs(get_class($object));

        // Prepare attributes
        foreach ($factory_attrs as $key => $kind) {
            if (!isset($attr[$key])) {
                $attr[$key] = $this->generateAttr($kind, $object);
            }
        }

        return $attr;
    }

    /**
     * Get factory attributes.
     *
     * @param string $model Model class name.
     *
     * @throws \League\FactoryMuffin\Exception\NoDefinedFactoryException
     *
     * @return array
     */
    private function getFactoryAttrs($model)
    {
        if (isset($this->factories[$model])) {
            return $this->factories[$model];
        }

        throw new NoDefinedFactoryException($model);
    }

    /**
     * Define a new model factory.
     *
     * @param string $model      Model class name.
     * @param array  $definition Array with definition of attributes.
     *
     * @return void
     */
    public function define($model, array $definition = array())
    {
        $this->factories[$model] = $definition;
    }

    /**
     * Generate the attributes.
     *
     * This method will return a string, or an instance of the model.
     *
     * @param string $kind   The kind of attribute that will be generated.
     * @param object $object The model instance.
     *
     * @return string|object
     */
    public function generateAttr($kind, $object = null)
    {
        $kind = Kind::detect($kind, $object, $this->faker);

        return $kind->generate();
    }

    /**
     * Load the specified factories.
     *
     * This method expects either a single path to a directory containing php
     * files, or an array of directory paths, and will include_once every file.
     * These files should contain factory definitions for your models.
     *
     * @param string|string[] $paths
     *
     * @throws \League\FactoryMuffin\Exception\DirectoryNotFoundException
     *
     * @return void
     */
    public function loadFactories($paths)
    {
        foreach ((array) $paths as $path) {
            if (!is_dir($path)) {
                throw new DirectoryNotFoundException($path);
            }

            $this->loadDirectory($path);
        }
    }

    /**
     * Load all the files in a directory.
     *
     * @param string $path
     *
     * @return void
     */
    private function loadDirectory($path)
    {
        $directory = new RecursiveDirectoryIterator($path);
        $iterator = new RecursiveIteratorIterator($directory);
        $files = new RegexIterator($iterator, '/^.+\.php$/i');

        foreach ($files as $file) {
            include_once $file->getPathName();
        }
    }
}
