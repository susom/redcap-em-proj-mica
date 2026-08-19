module.exports = {
  root: true,
  env: { browser: true, es2020: true },
  // Injected by the external module, not imported: MICA.php defines the JSMO and
  // the bootstrap payload on window before the bundle executes.
  globals: {
    mica_jsmo_module: 'readonly',
    mica_bootstrap: 'readonly',
    ExternalModules: 'readonly',
  },
  extends: [
    'eslint:recommended',
    'plugin:react/recommended',
    'plugin:react/jsx-runtime',
    'plugin:react-hooks/recommended',
  ],
  ignorePatterns: ['dist', '.eslintrc.cjs'],
  parserOptions: { ecmaVersion: 'latest', sourceType: 'module' },
  settings: { react: { version: '18.2' } },
  plugins: ['react-refresh'],
  rules: {
    'react/jsx-no-target-blank': 'off',
    // No component in this app declares propTypes - including the two providers
    // that predate this change - so the rule only ever produced noise and kept
    // `npm run lint` permanently red. Adopting prop-types project-wide instead is
    // a reasonable alternative; it just has to be an actual decision.
    'react/prop-types': 'off',
    'react-refresh/only-export-components': [
      'warn',
      { allowConstantExport: true },
    ],
  },
}
