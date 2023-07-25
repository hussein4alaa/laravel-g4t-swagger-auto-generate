# Swagger Laravel Autogenerate Package

The Swagger Laravel Autogenerate Package is a convenient tool that automatically generates Swagger documentation for your Laravel APIs based on your route definitions. It eliminates the need for manually documenting your API endpoints, saving you time and effort.



![Swagger Laravel Autogenerate Package](https://www.scottbrady91.com/img/logos/swagger-banner.png)


[![Total Downloads](http://poser.pugx.org/g4t/swagger/downloads)](https://packagist.org/packages/g4t/swagger)
[![Monthly Downloads](http://poser.pugx.org/g4t/swagger/d/monthly)](https://packagist.org/packages/g4t/swagger)
[![Daily Downloads](http://poser.pugx.org/g4t/swagger/d/daily)](https://packagist.org/packages/g4t/swagger)
[![License](http://poser.pugx.org/g4t/swagger/license)](https://packagist.org/packages/g4t/swagger)
[![Latest Stable Version](http://poser.pugx.org/g4t/swagger/v)](https://packagist.org/packages/g4t/swagger)

## Features

- Automatically generates Swagger documentation for Laravel APIs.
- Extracts route information, including URI, HTTP methods, route names, middleware, and more.
- Supports request validations and parameter definitions.
- Generates JSON output conforming to the Swagger/OpenAPI specification.
- Easy integration and configuration within Laravel projects.


## Installation

Install the Swagger Laravel Autogenerate Package via Composer:

```
composer require g4t/swagger
```

Now go to `app\Http\Kernel.php` and add this line

```
'api' => [
  // ... other middleware
  \G4T\Swagger\Middleware\SetJsonResponseMiddleware::class,
],
```
## Usage

1. After installing the package, publish the configuration file:
```
php artisan vendor:publish --provider "G4T\Swagger\SwaggerServiceProvider"
```

2. Configure the package by modifying the `config/swagger.php` file according to your needs. This file allows you to specify various settings for the Swagger documentation generation.

3. Access the generated Swagger documentation by visiting the `/swagger/documentation` route in your Laravel application. For example, `http://your-app-url/swagger/documentation`.

## Contributing

Contributions to the Swagger Laravel Autogenerate Package are always welcome! If you find any issues or have suggestions for improvements, please feel free to open an issue or submit a pull request.


## License

The Swagger Laravel Autogenerate Package is open-source software licensed under the [MIT license](LICENSE.md).

## Credits

The Swagger Laravel Autogenerate Package is developed and maintained by [HusseinAlaa](https://www.linkedin.com/in/hussein4alaa/).

## Additional Resources

- [Swagger Documentation](https://swagger.io/docs/)
- [Laravel Documentation](https://laravel.com/docs)
- [GitHub](https://github.com/hussein4alaa/laravel-g4t-swagger-auto-generate)
