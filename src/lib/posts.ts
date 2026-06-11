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
