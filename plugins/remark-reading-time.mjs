import { toString } from 'mdast-util-to-string';
import getReadingTime from 'reading-time';

/** Injecte `minutesRead` (entier ≥ 1) dans le frontmatter de chaque markdown. */
export function remarkReadingTime() {
  return function (tree, { data }) {
    const { minutes } = getReadingTime(toString(tree));
    data.astro.frontmatter.minutesRead = Math.max(1, Math.ceil(minutes));
  };
}
