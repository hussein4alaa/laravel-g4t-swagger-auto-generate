# Swagger Laravel Autogenerate Package

The Swagger Laravel Autogenerate Package is a convenient tool that automatically generates Swagger documentation for your Laravel APIs based on your route definitions. It eliminates the need for manually documenting your API endpoints, saving you time and effort.



![Swagger Laravel Autogenerate Package](https://www.scottbrady91.com/img/logos/swagger-banner.png)


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



## Usage

1. After installing the package, publish the configuration file:
```
php artisan vendor:publish
```
and select `G4T\Swagger\SwaggerServiceProvider`


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
- [GitHub Repository](https://github.com/your-repo-url)

Feel free to customize and expand this README file according to your specific package features, guidelines, and requirements.
