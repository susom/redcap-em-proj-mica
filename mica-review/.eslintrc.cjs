module.exports = {
  root: true,
  env: { browser: true, es2021: true, node: true },
  extends: [
    'eslint:recommended',
    'plugin:react/recommended',
    'plugin:react/jsx-runtime',
    'plugin:react-hooks/recommended',
  ],
  ignorePatterns: ['dist', '.eslintrc.cjs'],
  parserOptions: { ecmaVersion: 'latest', sourceType: 'module' },
  settings: { react: { version: '18.3' } },
  plugins: ['react-refresh'],
  globals: {
    // Injected by the external module, not imported: pages/review.php defines these on window
    // before the bundle executes.
    ExternalModules: 'readonly',
    mica_review: 'readonly',
  },
  rules: {
    // Off, matching mica-chatbot's documented decision rather than diverging from it: no component
    // in either app declares propTypes, so the rule only ever produced noise and kept `npm run
    // lint` permanently red. Adopting prop-types across both apps is a reasonable alternative - it
    // just has to be one decision, made once, rather than two configs that disagree.
    'react/prop-types': 'off',
    'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
    // The dashboard renders model output and reviewer text. Anything that would let a string reach
    // the DOM unescaped is an error here, not a style preference.
    'react/no-danger': 'error',
    'react/no-danger-with-children': 'error',
    'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
  },
  overrides: [
    {
      // Vitest globals, declared by hand rather than pulled in as another plugin: three lines beat
      // a dependency, and this list is the whole surface the tests use.
      files: ['**/*.test.{js,jsx}', 'src/test-setup.js'],
      globals: {
        describe: 'readonly',
        it: 'readonly',
        test: 'readonly',
        expect: 'readonly',
        vi: 'readonly',
        beforeEach: 'readonly',
        afterEach: 'readonly',
        beforeAll: 'readonly',
        afterAll: 'readonly',
      },
    },
  ],
}
