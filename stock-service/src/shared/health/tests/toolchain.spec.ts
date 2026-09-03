describe('stock-service toolchain', () => {
  it('executes TypeScript tests in NodeNext mode', () => {
    expect(import.meta.url.startsWith('file:')).toBe(true);
  });
});
