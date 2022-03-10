import React from 'react';

export default function ReactSelectExample() {
    return (
        <div className="grid grid-cols-1 gap-x-6 md:grid-cols-3">
            <div className="mt-6">
                <Label forInput={'category'}>category</Label>
                <Select
                    name="category"
                    id="category"
                    classNamePrefix="react-select"
                    className="react-select-container"
                    options={categories}
                    value={data.category}
                    defaultValue={data.category}
                    onChange={(e) => setData('category', e)}
                />
                {errors.category && <Error message={errors.category} />}
            </div>
            <div className="mt-6">
                <Label forInput={'tags'}>tags</Label>
                <Select
                    isMulti
                    name="tags"
                    id="tags"
                    classNamePrefix="react-select"
                    className="react-select-container"
                    options={tags}
                    defaultValue={data.tags}
                    onChange={(e) => setData('tags', e)}
                />
                {errors.tags && <Error message={errors.tags} />}
            </div>
        </div>
    );
}
