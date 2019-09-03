## Panogram Readme

This is a fork of the Panogram pedigree editor developed by Christopher Michaelides and Andrej Griniuk and licensed under LGPL v2.1.

The original repository can be found here : [https://github.com/panogram/panogram](https://github.com/panogram/panogram)

A much more advanced version of this editor is available at phenotips but the license was changed to AGPL.

[https://github.com/phenotips/phenotips/](https://github.com/phenotips/phenotips/)

The original purpose of the fork was to provide a redcap external module to edit pedigree diagrams.

To do this it was adapted to send the diagram information either via a shared browser local storage or a posted message.

There are still a large number of issues with the editor.

  - The editor is built on top of XWiki which expects access to the XWiki back-end, which will likely not be available, so the editor will do rest calls which fail.
  
 


## Try it out

[https://aehrc.github.io/panogram/test_opener.html](https://aehrc.github.io/panogram/test_opener.html)


## License

Panogram is distributed under the [LGPL version 2.1](http://www.gnu.org/licenses/lgpl-2.1.html) (GNU Lesser General Public License), a well known free software/open source license recognized both by the Free Software Foundation and the Open Source Initiative.
This means that every change made to the code must also be distributed under LGPL. Normally, users, distributors and integrators don't need to change the platform code, which means that their part of the license agreement is fulfilled by informing somehow the user of the license of PhenoTips, and provide a link to the default sources. External programs that talk to PhenoTips through other means don't have to be LGPL, any license is acceptable. This includes custom components, plugins or authenticators included in the platform, and remote tools that communicate through HTTP.
