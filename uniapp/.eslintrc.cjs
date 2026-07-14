/* Home Guardian uniapp ESLint（classic config，配合 eslint 8） */
module.exports = {
  root: true,
  env: {
    browser: true,
    es2022: true,
    node: true,
  },
  parser: 'vue-eslint-parser',
  parserOptions: {
    parser: '@typescript-eslint/parser',
    ecmaVersion: 2022,
    sourceType: 'module',
  },
  extends: [
    'eslint:recommended',
    'plugin:vue/vue3-recommended',
    'plugin:@typescript-eslint/recommended',
  ],
  plugins: ['@typescript-eslint'],
  globals: {
    uni: 'readonly',
    plus: 'readonly',
    wx: 'readonly',
    getApp: 'readonly',
    getCurrentPages: 'readonly',
    UniApp: 'readonly',
    UniNamespace: 'readonly',
  },
  rules: {
    'vue/multi-word-component-names': 'off',
    'vue/no-v-html': 'off',
    '@typescript-eslint/no-explicit-any': 'warn',
    '@typescript-eslint/no-non-null-assertion': 'off',
    'no-empty': ['error', { allowEmptyCatch: true }],
  },
  ignorePatterns: ['node_modules/', 'unpackage/', 'dist/', 'src/utils/uqrcode/'],
};
