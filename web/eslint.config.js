import js from '@eslint/js'
import reactHooks from 'eslint-plugin-react-hooks'
import tseslint from 'typescript-eslint'

export default tseslint.config(
    { ignores: ['dist', 'coverage', 'node_modules', '.vite'] },
    js.configs.recommended,
    ...tseslint.configs.strictTypeChecked,
    {
        files: ['**/*.{ts,tsx}'],
        languageOptions: {
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
        plugins: { 'react-hooks': reactHooks },
        rules: {
            ...reactHooks.configs.recommended.rules,
            // NFR-3: strict typing is enforced by tooling, not by good intentions.
            '@typescript-eslint/no-explicit-any': 'error',
        },
    },
    {
        files: ['vite.config.ts', 'eslint.config.js'],
        ...tseslint.configs.disableTypeChecked,
    },
)
