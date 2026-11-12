declare module '*.css';

interface Window {
  /** Set by Block/BlockRegistrar through an inline script before the editor script. */
  swgBlock?: { galleriesUrl?: string };
}

// The classic JSX factory is `createElement` from @wordpress/element (React); expose React's JSX types.
declare namespace JSX {
  type Element = React.JSX.Element;
  type ElementType = React.JSX.ElementType;
  type ElementClass = React.JSX.ElementClass;
  type ElementAttributesProperty = React.JSX.ElementAttributesProperty;
  type ElementChildrenAttribute = React.JSX.ElementChildrenAttribute;
  type IntrinsicAttributes = React.JSX.IntrinsicAttributes;
  type IntrinsicClassAttributes<T> = React.JSX.IntrinsicClassAttributes<T>;
  type IntrinsicElements = React.JSX.IntrinsicElements;
}
