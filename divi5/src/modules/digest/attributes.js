/**
 * Return the desktop value of one of our module attributes.
 *
 * Every Divi 5 attribute is responsive, even the ones we never vary by
 * device — the value always sits under desktop.value.
 *
 * @param attrs        All attributes of the module.
 * @param name         The name of the attribute inside digest.advanced.
 * @param defaultValue The value to use if nothing is set.
 *
 * @return {string}
 */
export const getAttrValue = (attrs, name, defaultValue) =>
  attrs?.digest?.advanced?.[name]?.desktop?.value ?? defaultValue;
