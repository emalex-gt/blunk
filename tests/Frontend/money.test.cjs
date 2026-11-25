const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

const source = fs.readFileSync(
    path.resolve(__dirname, '../../resources/js/utils/money.ts'),
    'utf8'
);

const compiled = ts.transpileModule(source, {
    compilerOptions: {
        target: ts.ScriptTarget.ES2020,
        module: ts.ModuleKind.CommonJS,
    },
}).outputText;

const loaded = { exports: {} };

new Function('module', 'exports', compiled)(
    loaded,
    loaded.exports
);

const { multiplyMoney, percentageMoney } = loaded.exports;

const multiplicationCases = [
    ['10', '1', 10.00],
    ['10', '1.0001', 10.00],
    ['10', '1.0004', 10.00],
    ['10', '1.0005', 10.01],
    ['10', '1.0006', 10.01],
    ['10', '1.2345', 12.35],
    ['10', '0.1005', 1.01],
    ['10', '0.0005', 0.01],
    ['1.005', '1', 1.01],
    ['2.675', '1', 2.68],
    ['99.99', '2.5000', 249.98],
    ['0', '1.0005', 0.00],
    ['10.00', '1,0005', 10.01],
    ['0.01', '0.5', 0.01],
];

for (const [price, quantity, expected] of multiplicationCases) {
    test('Money: ' + price + ' x ' + quantity, () => {
        assert.equal(multiplyMoney(price, quantity), expected);
    });
}

test('Multiple lines are rounded individually', () => {
    const total =
        multiplyMoney('10', '1.0005') +
        multiplyMoney('10', '1.0005');

    assert.equal(Math.round(total * 100), 2002);
});

const percentageCases = [
    ['10.05', '10', 1.01],
    ['10.01', '50', 5.01],
    ['100', '12.50', 12.50],
    ['99.99', '10', 10.00],
    ['20.02', '25', 5.01],
    ['10.00', '10.055', 1.01],
    ['100', '0', 0.00],
    ['0', '10', 0.00],
];

for (const [subtotal, percentage, expected] of percentageCases) {
    test('Discount: ' + percentage + '% of ' + subtotal, () => {
        assert.equal(
            percentageMoney(subtotal, percentage),
            expected
        );
    });
}
