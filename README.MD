# ProcessWire Laravel Vite Adapter
This ProcessWire module allows you to use the [laravel-vite plugin](https://github.com/laravel/vite-plugin) with ProcessWire. After installation, you can use the `vite()` helper to include assets that have been processed by Vite in your ProcessWire project.

```php
<!doctype html>
<head>
  <?= vite(['assets/css/app.css', 'assets/js/app.js']) ?>
</head>
```

> **Note**  
> Some features are not properly documented yet. Feel free to skim through the [source code](https://github.com/trk/processwire-vite/blob/main/Vite.php) if you think something is missing.

## Ready-to-use Configuration Files

When the module is installed, it will copy ready-to-use configuration files from the `stubs` directory to your `templates` directory. These files provide a starting point for your Vite configuration.

You can examine these configuration files in the [stubs directory on GitHub](https://github.com/trk/processwire-vite/tree/main/stubs) to better understand the default setup.

## Installation

As with most ProcessWire modules, you can use one of three different methods of installation:

## Installation

As with most ProcessWire modules, you can use one of three different methods of installation:

>Install the module from the [modules directory](https://modules.processwire.com/modules/vite/):

### Composer
```
composer require trk/processwire-vite Vite
```

### Git Submodule
```
git submodule add https://github.com/trk/processwire-vite.git site{-*}/modules/Vite
```

### Download
Download and copy this repository to `/site{-*}/modules/Vite`.

## Installing The Laravel Vite Plugin
Documentation for the Laravel Vite plugin can be found on the [Laravel website](https://laravel.com/docs/vite).

If you're starting a new frontend project, you need to pick a base folder where your unprocessed frontend code is located. Typically this is also the main folder of your ProcessWire project. So inside your main project folder (same folder where you can find your `composer.json`), run

```bash
npm run init
```

to initialize a new npm project. Now you can run

```bash
npm install --save-dev vite laravel-vite-plugin
```
to install the Vite and the Laravel Vite plugin.

It's also a good idea to add the commands to run Vite to your `package.json` (if they don't exist already):

```json
  "scripts": {
    "build": "vite build",
    "dev": "vite"
  }
```

Now you can tell Vite about the newly installed plugin by creating a new `vite.config.js` with the following content:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
  plugins: [
    laravel(['assets/css/app.css', 'assets/js/app.js']),
  ],
});
```

## Refreshing On Save
When your application is built using traditional server-side rendering, Vite can improve your development workflow by automatically refreshing the browser when you make changes to template or snippet files in your application. To get started, you can simply specify the refresh option.

```js
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'assets/css/app.css',
                'assets/js/app.js',
            ],
            refresh: [
                '/**',
                '/**/**',
                '../classes/**'
            ],
        }),   
    ]
});
```

## Autoloading Template Specific Assets
If you used the `@auto` option for your assets, you can do the same with [optional assets](#optional-assets).

## Optional Assets
When you use prepend an `@` to an asset, those assets are treated as optional.
Meaning the plugin will only include assets that actually exist at the given source path.

```php
<!doctype html>
<head>
  <?= vite([
    // Equivalent to this
    '@assets/css/templates/' . $page->template->name . '.css',
    '@assets/js/templates/' . $page->template->name . '.js',
  ]) ?>
</head>
```

> **Note**  
> Remember to include the optional assets in vite as well so that they are actually available once bundled.
> Assets not included in vite **will work in development** mode but **not when bundled**.

```js
export default defineConfig({
  plugins: [
      laravel([
        'assets/css/app.css',
        'assets/css/templates/home.css',
        'assets/js/app.js',
        'assets/js/templates/home.js',
      ]),
  ]
});
```

## React
If you build your front-end using the React framework you will also need to call the additional `vite()->reactRefresh()` method alongside your existing `vite()` call.

```php
<?= vite()->reactRefresh() ?>
<?= vite('assets/js/app.jsx') ?>
```

The `vite()->reactRefresh()` method must be called before the `vite()` call.

## Processing Static Assets With Vite
When referencing assets in your JavaScript or CSS, Vite automatically processes and versions them.
In addition, when your application is built using traditional server-side rendering, Vite can also process and version static assets that you reference in your templates.

However, in order to accomplish this, you need to make Vite aware of your assets by importing the static assets into the application's entry point.
For example, if you want to process and version all images stored in `assets/images` and all fonts stored in `assets/fonts`, you should add the following in your application's `assets/js/app.js` entry point:

```js
import.meta.glob([
  '../images/**',
  '../fonts/**',
]);
```

These assets will now be processed by Vite when running `npm run build`. You can then reference these assets in your templates using the `vite()->asset()` method, which will return the versioned URL for a given asset:

```php
<img src="<?= vite()->asset('assets/images/logo.png') ?>">
```

## Arbitrary Attributes
If you need to include additional attributes on your script and style tags, such as the `data-turbo-track` attribute, you may specify them via the plugin options.

```php
setting('vite.scriptTagAttributes', [
    'data-turbo-track' => 'reload', // Specify a value for the attribute...
    'async'            => true,     // Specify an attribute without a value...
    'integrity'        => false,    // Exclude an attribute that would otherwise be included...
]);

setting('vite.styleTagAttributes', [
    'data-turbo-track' => 'reload',
]);
```

If you need to conditionally add attributes, you may pass a callback that will receive the asset source path, its URL, its manifest chunk, and the entire manifest:

```php
setting('vite.scriptTagAttributes', fn (string $src, string $url, array $chunk, array $manifest) => [
    'data-turbo-track' => $src === 'assets/js/app.js' ? 'reload' : false,
]);

setting('vite.styleTagAttributes', fn (string $src, string $url, array $chunk, array $manifest) => [
    'data-turbo-track' => $chunk && $chunk['isEntry'] ? 'reload' : false,
]);
```

> **Note**  
> The `$chunk` and `$manifest` arguments will be empty while the Vite development server is running.

## Advanced Customization
Out of the box, Laravel's Vite plugin uses sensible conventions that should work for the majority of applications.
However, sometimes you may need to customize Vite's behavior.
To enable additional customization options, you can use the following options:

```php
setting('vite', [
    'hotFile' => fn () => wire('config')->paths->root . 'vite.hot',
    'buildDirectory' => 'bundle',
    'manifest' => 'assets.json'
]);
```

> **Note**
> If you need access to the View instance, you can use a callback function to define the option.
> Alternatively, you can configure Vite before load assets or in the template.

```php
vite()->instance()
    ->useHotFile(wire('config')->paths->root . 'vite.hot')
    ->useBuildDirectory('bundle')
    ->useManifest('assets.json');
```

```php
<!doctype html>
<head>
  <?=
    vite()->useHotFile(wire('config')->paths->root . 'vite.hot')
          ->useBuildDirectory('bundle')
          ->useManifest('assets.json')
          ->withEntries(['assets/js/app.js'])
  ?>
</head>
```

Within the `vite.config.js` file, you should then specify the same configuration:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            hotFile: '/vite.hot',
            buildDirectory: 'bundle',
            input: [
              'assets/js/app.js',
            ],
        }),
    ],
    build: {
      manifest: 'assets.json',
    },
});
```

## Commercial Usage
This plugin is free of charge, but please consider a [donation](https://patreon.com/ukyo) if you use it in a commercial project.

## License
MIT

## Credits
- [Iskender TOTOGLU](https://github.com/trk)
- [Lukas Kleinschmidt](https://github.com/lukasklei)
- [The Laravel Framework](https://github.com/laravel)

> A good portion of the documentation has been copied from the [Laravel website](https://laravel.com/docs/vite) and adapted to the ProcessWire implementation.