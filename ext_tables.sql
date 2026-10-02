#
# Processed files the consumer produced itself. Learning ignores them: only
# what a template requested is evidence of what templates request. Without
# this, every learned size would confirm itself with each upload and never
# drop out.
#
CREATE TABLE tx_kohasync_pregenerated (
	processedfile int(11) unsigned DEFAULT '0' NOT NULL,

	UNIQUE KEY processedfile (processedfile)
);
