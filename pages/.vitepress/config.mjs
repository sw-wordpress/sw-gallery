export default {
  title: 'SW Gallery',
  description: 'User documentation for the WordPress gallery plugin',
  lang: 'en-US',
  base: '/sw-gallery/',
  srcExclude: ['README.md'],
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started' },
      { text: 'Changelog', link: '/Changelog' }
    ],
    sidebar: [{
      text: 'User documentation',
      items: [
        { text: 'Overview', link: '/' },
        { text: 'Getting started', link: '/getting-started' },
        { text: 'Galleries and images', link: '/galleries' },
        { text: 'Layouts and presentation', link: '/layouts' },
        { text: 'Embedding', link: '/embedding' },
        { text: 'Settings, access and privacy', link: '/settings-and-access' },
        { text: 'AI assistants (MCP)', link: '/ai-assistants' },
        { text: 'Troubleshooting', link: '/troubleshooting' },
        { text: 'Changelog', link: '/Changelog' }
      ]
    }],
    search: { provider: 'local' },
    socialLinks: [{ icon: 'github', link: 'https://github.com/sw-wordpress/sw-gallery' }],
    editLink: {
      pattern: 'https://github.com/sw-wordpress/sw-gallery/edit/main/pages/:path',
      text: 'Edit this page on GitHub'
    },
    outline: [2, 3]
  }
}
