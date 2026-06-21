const Encore = require('@symfony/webpack-encore');

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')

    // App-Einstiegspunkt
    .addEntry('app', './assets/app.js')

    .splitEntryChunks()
    .enableReactPreset()
    .enableStimulusBridge('./assets/controllers.json')
    .enableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    // Versioning auch im Dev-Modus: Caddy liefert .js/.css mit
    // "Cache-Control: immutable" aus (siehe Caddyfile @static). Ohne Hash im
    // Dateinamen friert der Browser app.js dauerhaft ein und zieht Code-Änderungen
    // selbst per Hard-Reload nicht. Mit Hash erzwingt jeder Build eine neue URL.
    .enableVersioning(true)

    // PostCSS für Tailwind
    .enablePostCssLoader()

    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = '3.38';
    })
;

module.exports = Encore.getWebpackConfig();
