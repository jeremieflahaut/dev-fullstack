import { getCollection } from 'astro:content';

/**
 * Articles triés du plus récent au plus ancien.
 * Les drafts restent visibles en dev mais sont exclus du build de prod.
 */
export async function getPublishedPosts() {
  const posts = await getCollection('blog', ({ data }) =>
    import.meta.env.PROD ? !data.draft : true
  );
  return posts.sort((a, b) => b.data.pubDate.valueOf() - a.data.pubDate.valueOf());
}

/**
 * Tags des articles publiés, avec le nombre d'articles par tag.
 * Triés par fréquence décroissante, puis par ordre alphabétique.
 */
export async function getAllTags() {
  const posts = await getPublishedPosts();
  const counts = new Map<string, number>();
  for (const post of posts) {
    for (const tag of post.data.tags) {
      counts.set(tag, (counts.get(tag) ?? 0) + 1);
    }
  }
  return [...counts.entries()]
    .map(([tag, count]) => ({ tag, count }))
    .sort((a, b) => b.count - a.count || a.tag.localeCompare(b.tag));
}
