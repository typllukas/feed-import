import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import pluginVue from 'eslint-plugin-vue';

export default defineConfigWithVueTs(
    { ignores: ['.handover', 'public/build', 'vendor', 'storage', 'eslint.config.js'] },
    pluginVue.configs['flat/recommended'],
    vueTsConfigs.recommendedTypeChecked,
    {
        rules: {
            '@typescript-eslint/consistent-type-assertions': ['error', { assertionStyle: 'never' }],
            '@typescript-eslint/consistent-type-definitions': ['error', 'type'],
            '@typescript-eslint/explicit-function-return-type': ['error', { allowExpressions: true }],
            '@typescript-eslint/explicit-member-accessibility': 'error',
            '@typescript-eslint/no-deprecated': 'error',
            '@typescript-eslint/no-non-null-assertion': 'error',
            '@typescript-eslint/no-unnecessary-type-parameters': 'error',
            '@typescript-eslint/switch-exhaustiveness-check': 'error',
            'id-length': ['error', { min: 2, exceptions: ['id'] }],
            'no-restricted-syntax': [
                'error',
                {
                    selector: 'NewExpression[callee.name=/Error$/] :matches(Literal[raw=/[^\\x00-\\x7F]/], TemplateElement[value.raw=/[^\\x00-\\x7F]/])',
                    message: 'Error messages are for developers: English only.',
                },
                {
                    selector: ':matches(Program, ExportNamedDeclaration) > VariableDeclaration[kind="const"] > VariableDeclarator[id.typeAnnotation=undefined][init.type=/^(ObjectExpression|ArrayExpression)$/]',
                    message: 'Give a module-level object or array literal a type: `satisfies T`, an annotation, or `as const`.',
                },
            ],
            'vue/html-indent': ['warn', 4],
            'vue/no-unused-properties': 'error',
        },
    },
);
