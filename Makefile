.DEFAULT_GOAL := build
.PHONY: build dist release
build:
	npm --prefix apps/admin run build
	npm --prefix assets run build
	npm --prefix assets/blocks run build
dist:
	bin/build-zip.sh
	bin/verify-zip.sh "dist/sw-gallery-$$(bin/version.sh get).zip"
# Explicit operator command: commits version metadata and atomically pushes the branch and stable release tag.
release: export SWG_RELEASE_VERSION = $(VERSION)
release:
	bash bin/release.sh
