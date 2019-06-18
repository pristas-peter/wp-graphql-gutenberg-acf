const isAcfMetaFormat = (data) => {
    let result = false;
    
    const keys = Object.keys(data);

    for (const key of Object.keys(data)) {
        if (keys.indexOf(`_${key}`) > -1) {
            if (typeof data[`_${key}`] === 'string' && acf.get_field(data[`_${key}`])) {
                result = true;
                break;
            }
        }
    }

    return result;
}
const toAcfBlockFormat = data => {
    if (isAcfMetaFormat(data)) {
        return Object.keys(data).reduce((value, key) => {
            if (key.startsWith('_')) {
    
                const field = acf.get_field(data[key]);
                if (field) {
                    const serialized = acf.serialize(field);
    
                    Object.keys(serialized).forEach(id => {
                        Object.assign(value, serialized[id]);
                    })
                }
            }
    
            return value;
    
        }, {});
    }

    return data;
}
const visitBlocks = (blocks) => {
    blocks.forEach(block => {
        if (block.name.startsWith('acf/')) {
            block.attributes.data = toAcfBlockFormat(block.attributes.data);
        }

        if (block.innerBlocks) {
            visitBlocks(block.innerBlocks); 
        }
    });

    return blocks;
}

wp.hooks.addFilter('wpGraphqlGutenberg.postContentBlocks', 'wpGraphqlGutenbergAcf.postContentBlocks', (blocks) => {    
    return visitBlocks(blocks);
});

wp.hooks.addFilter('wpGraphqlGutenberg.reusableBlocks', 'wpGraphqlGutenbergAcf.reusableBlocks', (blockMap) => {    
    Object.keys(blockMap).forEach(key => {
        visitBlocks([blockMap[key]]);
    })

    return blockMap;
});
